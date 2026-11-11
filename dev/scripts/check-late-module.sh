#!/bin/bash
# Check that a module added on plugins_loaded (priority 20, as a plugin loaded after YayMatik would) is registered, trigger included (wp-cli).
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
exit( $fails ? 1 : 0 );
'
