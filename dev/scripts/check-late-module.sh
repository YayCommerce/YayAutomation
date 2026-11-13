#!/bin/bash
# Check that a module added on plugins_loaded (priority 20, as a plugin loaded after YayMatik would) is registered, trigger included,
# and that a module registered on yaymatik_register there works: its steps, yaymatik_fire, a refused declaration (wp-cli).
#   ./run.sh check-late-module [--wp-env|--wp-env-min]
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1

# --require runs before WordPress loads, so the filter goes in on a WordPress hook like a real plugin's would.
# The file sits inside the checkout, so the wp-env container sees it.
early=$(mktemp dev/.late-module.XXXXXX) && chmod 644 "$early" || exit 1
trap 'rm -f "$early"' EXIT
cat > "$early" <<'PHP'
<?php
WP_CLI::add_wp_hook(
    'plugins_loaded',
    function () {
        class Late_Check_Trigger extends \YayMatik\Workflow\Triggers\OrderCreated {
            public function id(): string {
                return 'late_check';
            }
            public function listen( callable $emit ): void {
                add_action( 'yaymatik_late_check', fn() => $emit( [] ) );
            }
        }
        class Late_Check_Module extends \YayMatik\Workflow\Modules\Module {
            public function id(): string {
                return 'late_check';
            }
            public function label(): string {
                return 'Late check';
            }
            public function description(): string {
                return '';
            }
            public function logo(): string {
                return 'https://example.test/late.svg';
            }
            public function triggers(): array {
                return [ Late_Check_Trigger::class ];
            }
        }
        add_filter( 'yaymatik_modules', fn( $modules ) => array_merge( $modules, [ Late_Check_Module::class ] ) );
        add_action(
            'yaymatik_register',
            function ( $registrar ) {
                $module = $registrar->module(
                    [
                        'id'    => 'late_ext',
                        'label' => 'Late extension',
                    ]
                );
                $module->trigger(
                    'late_ext_done',
                    [
                        'label'       => 'Late extension event',
                        'description' => 'Fired by the check.',
                        'provides'    => [ 'customer' ],
                    ]
                );
                $module->action(
                    'late_ext_act',
                    [
                        'label'       => 'Late extension action',
                        'description' => 'Does nothing.',
                        'run'         => fn( array $config, $run ) => $run->done(),
                    ]
                );
                $module->action( 'late_ext_bad', [ 'label' => 'No description, no run' ] );
            }
        );
    },
    20
);
PHP

"${WP[@]}" --require="$early" eval '
use YayMatik\Workflow\ModuleRegistry;
$fails = 0;
$ok    = function ( $pass, $what ) use ( &$fails ) {
    echo ( $pass ? "PASS " : "FAIL " ), $what, "\n";
    $fails += $pass ? 0 : 1;
};
$ok( isset( ModuleRegistry::modules()["late_check"] ), "module added on plugins_loaded 20 is loaded" );
$ok( "late_check" === ( ModuleRegistry::plugins( ModuleRegistry::modules() )["late_check"] ?? null ), "its trigger is registered under it" );
// The dispatcher listens only to active triggers.
add_filter( "pre_option_yaymatik_active_triggers", fn() => [ "late_check", "order_created" ] );
\YayMatik\Workflow\Core\Runtime\Runtime::dispatcher()->listen_active();
$ok( (bool) has_action( "yaymatik_late_check" ), "once active, the dispatcher listens to its trigger" );
$ok( (bool) has_action( "woocommerce_new_order" ), "core triggers still listen" );

// A registrar module, added on plugins_loaded 20 too.
require "dev/scripts/check-run.php";
use YayMatik\Workflow\Core\Definition\Catalog;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Extension\Diagnostics;
$ok( isset( StepRegistry::triggers()["late_ext_done"] ), "a registrar trigger is registered" );
$ok( "late_ext" === ( Catalog::to_client()["steps"]["late_ext_act"]["plugin"] ?? null ), "its action is in the catalog under its module" );
$ok( null === StepRegistry::get( "late_ext_bad" ), "a bad declaration is left out" );
$errors = array_filter( Diagnostics::recent( 1 ), fn( $entry ) => "late_ext" === $entry["module"] );
$ok( "action late_ext_bad" === ( array_values( $errors )[0]["callback"] ?? null ), "and recorded as a diagnostic; the request goes on" );

$admin = (int) get_users( [ "role" => "administrator", "number" => 1, "fields" => "ID" ] )[0];
wp_set_current_user( $admin );
yaymatik_check_remove_workflows( "Check late module:" );
$request = new WP_REST_Request( "POST", "/yaymatik/v1/workflows" );
$request->set_header( "content-type", "application/json" );
$node    = fn( $id, $kind, $y, $block ) => [ "id" => $id, "kind" => $kind, "position" => [ "x" => 0, "y" => $y ], "data" => [ "block" => $block, "config" => (object) [] ] ];
$request->set_body( wp_json_encode( [ "name" => "Check late module: fire (temporary)", "graph" => [ "nodes" => [ $node( "t", "trigger", 0, "late_ext_done" ), $node( "a", "action", 170, "late_ext_act" ) ], "edges" => [ [ "id" => "e1", "source" => "t", "target" => "a" ] ] ] ] ) );
$flow = (int) ( rest_do_request( $request )->get_data()["id"] ?? 0 );
$ok( 200 === rest_do_request( new WP_REST_Request( "POST", "/yaymatik/v1/workflows/{$flow}/activate" ) )->get_status(), "a workflow on the registrar trigger activates" );
remove_all_filters( "pre_option_yaymatik_active_triggers" );
$since = (int) $GLOBALS["wpdb"]->get_var( "SELECT MAX(id) FROM " . RunStore::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
do_action( "yaymatik_fire", "late_ext_done", [ "user_id" => $admin, "junk" => 1 ] );
\YayMatik\Workflow\Core\Runtime\Runtime::dispatcher()->flush();
$started = (int) $GLOBALS["wpdb"]->get_var( $GLOBALS["wpdb"]->prepare( "SELECT COUNT(*) FROM " . RunStore::table() . " WHERE id > %d AND workflow_id = %d AND subject = %s", $since, $flow, "user:{$admin}" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$ok( 1 === $started, "do_action( yaymatik_fire ) starts one run about user:<id>" );
$ok( "payload late_ext_done" === ( array_values( array_filter( Diagnostics::recent( 1 ), fn( $entry ) => "late_ext" === $entry["module"] && "payload late_ext_done" === $entry["callback"] ) )[0]["callback"] ?? null ), "an unknown payload key is a diagnostic" );

$left = yaymatik_check_remove_runs( $since, [ $flow ] );
$ok( [] === yaymatik_check_remove_workflows( "Check late module:" ) && 0 === $left["runs"], "cleanup: no workflow or run left" );
$stored = get_option( Diagnostics::OPTION, [] );
update_option( Diagnostics::OPTION, array_filter( (array) $stored, fn( $entry ) => "late_ext" !== ( $entry["module"] ?? "" ) ), true );
exit( $fails ? 1 : 0 );
'
