<?php
/**
 * One check-extension.sh phase per wp-cli process (setup, sent, off, on, privacy, measures, cleanup); ids kept in an option between them.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.DB.DirectDatabaseQuery -- wp eval-file runs in global scope; check rows.

require_once __DIR__ . '/check-run.php';

use YayMatik\Engine\Privacy\PersonalData;
use YayMatik\Workflow\Core\Definition\Catalog;
use YayMatik\Workflow\Core\Runtime\HealthCheck;
use YayMatik\Workflow\Core\Runtime\Runtime;
use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\WorkflowStore;
use YayMatik\Workflow\Extension\Diagnostics;
use YayMatik\Workflow\ModuleRegistry;

const CHECK_EXT_STATE  = 'yaymatik_check_extension';
const CHECK_EXT_PREFIX = 'Check extension:';
const CHECK_EXT_EMAIL  = 'acme.check@example.test';

$phase = $args[0] ?? '';
$state = (array) get_option( CHECK_EXT_STATE, [] );
$fails = 0;
$ok    = function ( $pass, string $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $fails += $pass ? 0 : 1;
};
$admin = (int) get_users(
    [
        'role'   => 'administrator',
        'number' => 1,
        'fields' => 'ID',
    ]
)[0];

/** A REST request as the admin; the response. */
$rest    = function ( string $method, string $route, ?array $body = null ) use ( $admin ) {
    $user = get_current_user_id();
    wp_set_current_user( $admin );
    $request = new WP_REST_Request( $method, '/yaymatik/v1/' . $route );
    if ( null !== $body ) {
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );
    }
    $response = rest_do_request( $request );
    wp_set_current_user( $user );
    return $response;
};
$node    = fn( string $id, string $kind, int $y, string $block, array $config = [] ) => [
    'id'       => $id,
    'kind'     => $kind,
    'position' => [
        'x' => 0,
        'y' => $y,
    ],
    'data'     => [
        'block'  => $block,
        'config' => (object) $config,
    ],
];
$line    = function ( array ...$nodes ) {
    $edges = [];
    $count = count( $nodes );
    for ( $i = 1; $i < $count; $i++ ) {
        $edges[] = [
            'id'     => "e{$i}",
            'source' => $nodes[ $i - 1 ]['id'],
            'target' => $nodes[ $i ]['id'],
        ];
    }
    return [
        'nodes' => $nodes,
        'edges' => $edges,
    ];
};
$runs_of = fn( int $workflow, int $since ) => array_map( 'intval', $GLOBALS['wpdb']->get_col( $GLOBALS['wpdb']->prepare( 'SELECT id FROM %i WHERE workflow_id = %d AND id > %d ORDER BY id', RunStore::table(), $workflow, $since ) ) );
$steps   = fn( int $run ) => array_column( RunStepStore::for_run( $run ), null, 'step_id' );

switch ( $phase ) {
    case 'setup':
        // 1. Register.
        $modules = ModuleRegistry::modules();
        $ok( isset( $modules['acme_rewards'] ), 'register: the acme_rewards module is loaded' );
        $catalog = Catalog::to_client();
        foreach ( [ 'acme_rewards_points_earned', 'acme_rewards_reward_redeemed', 'acme_rewards_add_points', 'acme_rewards_send_email' ] as $id ) {
            $ok( 'acme_rewards' === ( $catalog['steps'][ $id ]['plugin'] ?? null ), "register: {$id} is in the catalog" );
        }
        $ok( in_array( 'acme_rewards_reward', array_column( $catalog['data'], 'id' ), true ), 'register: data item acme_rewards_reward is in the catalog' );
        $ok( in_array( 'acme_rewards_welcome_bonus', array_column( $catalog['templates'], 'id' ), true ), 'register: template acme_rewards_welcome_bonus is offered' );
        $acme = array_values( array_filter( HealthCheck::extensions(), fn( $row ) => 'acme_rewards' === $row['id'] ) );
        $ok( $acme && ! $acme[0]['errors'], 'register: Health check › Extensions lists Acme Rewards with no errors' );

        // 2. Run, set up here: a customer, a design, three workflows. The email is sent by the background job (phase `sent`).
        yaymatik_check_remove_workflows( CHECK_EXT_PREFIX );
        $customer = wp_insert_user(
            [
                'user_login' => 'acme-check-' . wp_rand(),
                'user_email' => CHECK_EXT_EMAIL,
                'user_pass'  => wp_generate_password(),
                'first_name' => 'Ann',
                'role'       => 'customer',
            ]
        );
        $design   = wp_insert_post(
            [
                'post_type'    => Acme_Rewards::DESIGN,
                'post_status'  => 'publish',
                'post_title'   => 'Check extension design',
                'post_content' => '<p>Balance: {{ customer.acme_rewards_points }}</p>',
            ]
        );
        $email    = [
            'subject'   => 'Hi {{ customer.first_name }}, +{{ trigger.points }} points',
            'design_id' => (string) $design,
        ];
        $create   = function ( string $name, array $graph ) use ( $rest ) {
            return (int) ( $rest(
                'POST',
                'workflows',
                [
                    'name'  => CHECK_EXT_PREFIX . " {$name} (temporary)",
                    'graph' => $graph,
                ]
            )->get_data()['id'] ?? 0 );
        };
        $points   = $line( $node( 't', 'trigger', 0, 'acme_rewards_points_earned' ), $node( 'add', 'action', 170, 'acme_rewards_add_points', [ 'points' => 5 ] ), $node( 'mail', 'action', 340, 'acme_rewards_send_email', $email ) );
        $state    = [
            'customer' => (int) $customer,
            'design'   => (int) $design,
            'since'    => (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', RunStore::table() ) ),
            'points'   => $create( 'points', $points ),
            'parked'   => $create( 'parked', $points ),
            'paid'     => $create(
                'order paid',
                $line(
                    $node( 't', 'trigger', 0, 'order_paid' ),
                    $node( 'add', 'action', 170, 'acme_rewards_add_points', [ 'points' => 10 ] ),
                    $node(
                        'note',
                        'action',
                        340,
                        'add_order_note',
                        [
                            'type' => 'private',
                            'note' => 'Check extension: W3 ran',
                        ]
                    )
                )
            ),
        ];
        foreach ( [ 'points', 'paid' ] as $key ) {
            $ok( 200 === $rest( 'POST', "workflows/{$state[ $key ]}/activate" )->get_status(), "run: workflow \"{$key}\" activates" );
        }
        $state['graph'] = md5( (string) wp_json_encode( WorkflowStore::find( $state['points'] )['graph'] ) );

        // The editor URL is built through REST, on this site's origin.
        $editor = $rest(
            'POST',
            "workflows/{$state['points']}/editor",
            [
                'nodeId' => 'mail',
                'config' => $email,
            ]
        );
        $url    = (string) ( $editor->get_data()['url'] ?? '' );
        $ok( 200 === $editor->get_status() && 0 === strpos( $url, admin_url( 'admin.php?page=acme-rewards-designer' ) ) && false !== strpos( $url, "design={$design}" ), 'editor: REST builds the designer URL for the step\'s design' );
        // Only Acme's designer URL moves to another site; core's admin_url() stays.
        $elsewhere = fn( $url, $path ) => 0 === strpos( (string) $path, 'admin.php?page=acme-rewards-designer' ) ? 'https://elsewhere.example/wp-admin/' . $path : $url;
        add_filter( 'admin_url', $elsewhere, 10, 2 );
        $refused   = 400 === $rest(
            'POST',
            "workflows/{$state['points']}/editor",
            [
                'nodeId' => 'mail',
                'config' => $email,
            ]
        )->get_status();
        $noted     = array_filter( Diagnostics::recent( 1 ), fn( $entry ) => 'acme_rewards' === $entry['module'] && 'editor url' === $entry['callback'] );
        $ok( $refused && $noted, 'editor: a URL on another origin than the admin\'s is refused, with a diagnostic' );
        remove_filter( 'admin_url', $elsewhere, 10 );

        // Fire: earn() calls yaymatik_fire behind Acme's hourly throttle; the run is queued at once, not run.
        delete_transient( 'acme_ym_' . $customer );
        Acme_Rewards::instance()->earn( (int) $customer, 25 );
        Runtime::dispatcher()->flush();
        $ok( 1 === count( $runs_of( $state['points'], $state['since'] ) ), 'run: earn() starts one run' );
        // Acme's own throttle off again: in the run, Add reward points fires the trigger once more, and only YayMatik's loop guard stops it.
        delete_transient( 'acme_ym_' . $customer );
        break;

    case 'sent':
        $runs = $runs_of( (int) $state['points'], (int) $state['since'] );
        $run  = $runs ? RunStore::find( $runs[0] ) : null;
        $ok( (bool) get_transient( 'acme_ym_' . $state['customer'] ), 'loop guard: in the run, Add reward points fired the trigger again (its throttle is set again)' );
        $ok( 1 === count( $runs ), 'loop guard: still one run' );
        delete_transient( 'acme_ym_' . $state['customer'] );
        $ok( 'completed' === ( $run['status'] ?? null ), 'run: Completed after the background job' );
        $done = $run ? $steps( (int) $run['id'] ) : [];
        $ok( 'completed' === ( $done['add']['status'] ?? null ) && 30 === (int) ( $done['add']['output']['balance'] ?? 0 ), 'run: Add reward points output balance 30 (25 + 5)' );
        $ok( strtolower( CHECK_EXT_EMAIL ) === ( $done['mail']['output']['to'] ?? null ), 'run: the email step outputs to = the customer (run detail "Sent to …")' );
        $ok( '25' === (string) ( $run['scope']['trigger']['points'] ?? '' ), 'run: the fact points = 25 is in the run\'s trigger scope' );

        $log   = is_file( (string) ( $args[1] ?? '' ) ) ? file( $args[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : [];
        $mails = array_values( array_filter( array_map( 'json_decode', (array) $log ), fn( $mail ) => $mail && CHECK_EXT_EMAIL === $mail->to ) );
        $mail  = $mails[0] ?? null;
        $ok( 1 === count( $mails ), 'mail: one email to the customer' );
        $ok( $mail && 0 === $mail->user && ! $mail->post, 'mail: built and sent with no logged-in user and no global $post (a background job)' );
        $ok( $mail && 'Hi Ann, +25 points' === $mail->subject, 'mail: merge tags rendered in the subject' );
        $ok( $mail && false !== strpos( $mail->message, '<h1>Hi Ann</h1>' ) && false !== strpos( $mail->message, 'Balance: 30' ), 'mail: the design with the customer\'s name and a merge tag in its body' );
        $ok( $mail && 1 === substr_count( $mail->message, 'yaymatik_unsub=' ) && false !== strpos( $mail->message, '>Unsubscribe</a>' ), 'mail: Acme\'s own unsubscribe link, once (no footer added)' );
        $ok( $mail && false === strpos( $mail->message, 'template_container' ), 'mail: Acme\'s whole document, not WooCommerce\'s wrapper' );
        $ok( $mail && 2 === count( preg_grep( '/^List-Unsubscribe(-Post)?:/i', $mail->headers ) ), 'mail: List-Unsubscribe and List-Unsubscribe-Post headers' );
        break;

    case 'off':
        $ok( ! isset( ModuleRegistry::modules()['acme_rewards'] ), 'off: Acme Rewards is deactivated' );
        $get   = $rest( 'GET', "workflows/{$state['parked']}" );
        $graph = $get->get_data()['graph'] ?? [];
        $ok( 200 === $get->get_status() && in_array( 'acme_rewards_send_email', array_column( array_column( $graph['nodes'] ?? [], 'data' ), 'block' ), true ), 'off: GET a workflow with Acme steps: 200, steps there' );
        $ok( 200 === $rest( 'PUT', "workflows/{$state['parked']}", [ 'graph' => $graph ] )->get_status(), 'off: saving it unchanged: 200' );
        $added            = $graph;
        $added['nodes'][] = $node( 'new', 'action', 510, 'acme_rewards_add_points', [ 'points' => 1 ] );
        $added['edges'][] = [
            'id'     => 'e-new',
            'source' => 'mail',
            'target' => 'new',
        ];
        $ok( 400 === $rest( 'PUT', "workflows/{$state['parked']}", [ 'graph' => $added ] )->get_status(), 'off: adding a new Acme step: 400' );
        $activate = $rest( 'POST', "workflows/{$state['parked']}/activate" );
        $ok( 400 === $activate->get_status() && in_array( 'missing_plugin', array_column( (array) ( $activate->get_data()['data']['errors'] ?? [] ), 'code' ), true ), 'off: activating it: 400 missing_plugin' );

        $order = wc_create_order( [ 'customer_id' => (int) $state['customer'] ] );
        $order->set_billing_email( CHECK_EXT_EMAIL );
        $order->save();
        $state['orders'][] = $order->get_id();
        $run               = yaymatik_check_run( (int) $state['paid'], 'order_paid', [ 'order_id' => $order->get_id() ] );
        $byid              = array_column( $run['steps'] ?? [], null, 'step_id' );
        $ok( 'completed' === ( $run['status'] ?? null ), 'off: an active "Order paid" workflow still completes' );
        $ok( 'skipped' === ( $byid['add']['status'] ?? null ) && 'Acme Rewards isn’t active.' === ( $byid['add']['error'] ?? null ), 'off: its Acme step is Skipped "Acme Rewards isn’t active."' );
        $ok( in_array( 'Check extension: W3 ran', array_column( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' ), true ), 'off: the step after it ran (order note added)' );
        $check = HealthCheck::missing_plugin( WorkflowStore::all() );
        $named = array_column( $check['workflows'], 'name', 'id' );
        $ok( 0 === strpos( $named[ (string) $state['points'] ] ?? '', CHECK_EXT_PREFIX ) && 0 === strpos( $named[ (string) $state['paid'] ] ?? '', CHECK_EXT_PREFIX ) && ! isset( $named[ (string) $state['parked'] ] ), 'off: Health check missing_plugin lists the active Acme workflows by name, not the inactive one' );
        break;

    case 'on':
        $ok( isset( ModuleRegistry::modules()['acme_rewards'] ), 'on: Acme Rewards is active again' );
        $ok( 200 === $rest( 'POST', "workflows/{$state['parked']}/activate" )->get_status(), 'on: the parked workflow activates' );
        $order = wc_create_order( [ 'customer_id' => (int) $state['customer'] ] );
        $order->save();
        $state['orders'][] = $order->get_id();
        $run               = yaymatik_check_run( (int) $state['paid'], 'order_paid', [ 'order_id' => $order->get_id() ] );
        $byid              = array_column( $run['steps'] ?? [], null, 'step_id' );
        $ok( 'completed' === ( $run['status'] ?? null ) && 'completed' === ( $byid['add']['status'] ?? null ), 'on: the same path completes, Acme step included' );
        $ok( md5( (string) wp_json_encode( WorkflowStore::find( (int) $state['points'] )['graph'] ) ) === $state['graph'], 'on: nothing was rewritten (graph unchanged)' );
        break;

    case 'privacy':
        $redeemed      = $rest(
            'POST',
            'workflows',
            [
                'name'  => CHECK_EXT_PREFIX . ' reward (temporary)',
                'graph' => $line(
                    $node( 't', 'trigger', 0, 'acme_rewards_reward_redeemed' ),
                    $node(
                        'wait',
                        'action',
                        170,
                        'wait',
                        [
                            'delay' => [
                                'value' => 1,
                                'unit'  => 'days',
                            ],
                        ]
                    )
                ),
            ]
        );
        $flow          = (int) ( $redeemed->get_data()['id'] ?? 0 );
        $state['flow'] = $flow;
        $rest( 'POST', "workflows/{$flow}/activate" );
        $since  = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', RunStore::table() ) );
        $reward = Acme_Rewards::instance()->redeem( (int) $state['customer'], 'Free shipping', 20 );
        Runtime::dispatcher()->flush();
        $runs  = $runs_of( $flow, $since );
        $about = $runs ? RunStore::find( $runs[0] ) : null;
        $ok( "acme_rewards_reward:{$reward}" === ( $about['subject'] ?? null ), 'privacy: "Reward redeemed" starts a run about acme_rewards_reward:<id>' );
        $items = array_column( PersonalData::get_instance()->export( CHECK_EXT_EMAIL, 1 )['data'], 'item_id' );
        $ok( $about && in_array( 'yaymatik-run-' . $about['id'], $items, true ), 'privacy: export for the customer lists that run (privacy_subjects)' );
        $state['rewards'][] = $reward;
        break;

    case 'measures':
        $dir   = WP_PLUGIN_DIR . '/acme-rewards';
        $code  = (string) file_get_contents( $dir . '/acme-rewards.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $lines = 0;
        foreach ( glob( $dir . '/*.php' ) as $file ) {
            $lines += count( file( $file ) );
        }
        preg_match( '/function register\( \$registrar \) \{(.*?)\n    \}\n/s', $code, $register );
        $calls = [];
        preg_match_all( '/\$(?:registrar|module|run|customer)->([a-z_]+)\(/', $code, $found );
        foreach ( $found[1] as $method ) {
            $calls[ $method ] = true;
        }
        preg_match_all( "/'([a-z_]+)'\s*=>/", $register[1] ?? '', $keys );
        echo 'MEASURE example plugin: ', (int) $lines, ' lines in ', count( glob( $dir . '/*.php' ) ), ' PHP files (', count( explode( "\n", $register[1] ?? '' ) ), " lines in register())\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo 'MEASURE public API used: hooks yaymatik_register, yaymatik_fire; methods ', implode( ', ', array_keys( $calls ) ), ' (', count( $calls ), ")\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo 'MEASURE declaration keys used in register(): ', count( array_unique( $keys[1] ) ), "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        break;

    case 'cleanup':
        // Only this check's runs: its workflows' and its orders', after the setup.
        $mine = array_values( array_filter( array_map( 'intval', [ $state['points'] ?? 0, $state['parked'] ?? 0, $state['paid'] ?? 0, $state['flow'] ?? 0 ] ) ) );
        $runs = isset( $state['since'] ) && $mine ? yaymatik_check_remove_runs( (int) $state['since'], $mine, $state['orders'] ?? [] ) : [
            'runs' => 0,
            'jobs' => 0,
        ];
        $left = yaymatik_check_remove_workflows( CHECK_EXT_PREFIX );
        foreach ( $state['orders'] ?? [] as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->delete( true );
            }
        }
        foreach ( array_merge( [ (int) ( $state['design'] ?? 0 ) ], $state['rewards'] ?? [] ) as $post_id ) {
            wp_delete_post( (int) $post_id, true );
        }
        if ( ! empty( $state['customer'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            delete_transient( 'acme_ym_' . $state['customer'] );
            wp_delete_user( (int) $state['customer'] );
        }
        // The editor check's own diagnostic; Acme's real ones stay.
        update_option( Diagnostics::OPTION, array_values( array_filter( (array) get_option( Diagnostics::OPTION, [] ), fn( $entry ) => ! ( 'acme_rewards' === ( $entry['module'] ?? '' ) && 'editor url' === ( $entry['callback'] ?? '' ) ) ) ), true );
        delete_option( CHECK_EXT_STATE );
        $ok( [] === $left && 0 === $runs['runs'] && 0 === $runs['jobs'] && ! get_user_by( 'email', CHECK_EXT_EMAIL ), 'cleanup: no workflow, run, job or customer left' );
        exit( $fails ? 1 : 0 );
}

update_option( CHECK_EXT_STATE, $state, false );
exit( $fails ? 1 : 0 );
