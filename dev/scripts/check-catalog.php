<?php
/**
 * The custom workflow catalog on the real tables and real orders at a random `.test` domain, through the real Dispatcher.
 * Runs stay queued, their jobs removed. Two checks start a second wp-cli process, as GET_LOCK is re-entrant in one session.
 * Refuses to run while the site has an active workflow on the triggers it uses: they would start on its orders.
 * Cleanup::run() also deletes the site's own old runs and carts, and claims of its deleted workflows.
 * Prints PASS / FAIL lines; removes its rows. Run through check-catalog.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

use Automattic\WooCommerce\Utilities\OrderUtil;
use YayMatik\Workflow\Actions\UpdateOrderMeta;
use YayMatik\Workflow\Conditions\Criteria;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Definition\GraphSchema;
use YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Core\Runtime\Cleanup;
use YayMatik\Workflow\Core\Runtime\Runtime;
use YayMatik\Workflow\Core\Steps\Trigger;
use YayMatik\Workflow\Core\Steps\StepResult;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\WorkflowStore;

if ( ! function_exists( 'WC' ) ) {
    WP_CLI::error( 'WooCommerce is not active.' );
}

// WooCommerce's own order emails stay off.
foreach ( WC()->mailer()->get_emails() as $wc_email ) {
    add_filter( 'woocommerce_email_enabled_' . $wc_email->id, '__return_false' );
}

global $wpdb;
$posts_store = defined( 'YAYMATIK_CHECK_POSTS_STORE' );
if ( $posts_store && OrderUtil::custom_orders_table_usage_is_enabled() ) {
    WP_CLI::error( 'Could not switch this process to the posts store.' );
}
echo 'Order store: ', OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'posts', "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

$option = WorkflowStore::ACTIVE_TRIGGERS_OPTION;
$domain = 'x' . wp_rand( 1000, 9999 ) . '.test';
$flows  = [];
$orders = [];

$fails = 0;
$ok    = function ( bool $pass, string $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $fails += $pass ? 0 : 1;
};

$cleanup = function () use ( $wpdb, &$flows, &$orders ) {
    $runs_table = RunStore::table();
    foreach ( $flows as $flow_id ) {
        WorkflowStore::deactivate( $flow_id );
        WorkflowStore::delete( $flow_id );
        $run_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$runs_table} WHERE workflow_id = %d", $flow_id ) ) );
        foreach ( $run_ids as $run_id ) {
            as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $run_id ], ActionSchedulerQueue::GROUP );
        }
        if ( $run_ids ) {
            RunStore::delete_with_steps( $run_ids );
        }
    }
    foreach ( $orders as $order_id ) {
        $saved_order = wc_get_order( $order_id );
        if ( $saved_order ) {
            $saved_order->delete( true );
        }
    }
};
register_shutdown_function( $cleanup );

/** A graph: this trigger → Add order note. */
$graph = fn( string $trigger, array $trigger_config = [] ) => GraphSchema::prepare(
    [
        'nodes' => [
            [
                'id'       => 'trigger',
                'kind'     => 'trigger',
                'type'     => 'trigger',
                'position' => [
                    'x' => 0,
                    'y' => 0,
                ],
                'data'     => [
                    'block'  => $trigger,
                    'config' => $trigger_config,
                ],
            ],
            [
                'id'       => 'note',
                'kind'     => 'action',
                'type'     => 'action',
                'position' => [
                    'x' => 0,
                    'y' => 100,
                ],
                'data'     => [
                    'block'  => 'add_order_note',
                    'config' => [
                        'note' => 'check-catalog',
                        'type' => 'private',
                    ],
                ],
            ],
        ],
        'edges' => [
            [
                'id'     => 'e',
                'source' => 'trigger',
                'target' => 'note',
            ],
        ],
    ]
);

/** A new inactive workflow on this trigger; its id. */
$flow = function ( string $trigger ) use ( &$flows, $graph ): int {
    $created = WorkflowStore::create( 'check-catalog', $graph( $trigger ) );
    if ( ! is_array( $created ) ) {
        WP_CLI::error( 'Could not create a temporary workflow.' );
    }
    $flows[] = (int) $created['id'];
    return (int) $created['id'];
};

/** A real order at the check's domain; its id. */
$order = function ( array $props = [] ) use ( &$orders, $domain ): int {
    $new = new WC_Order();
    $new->set_billing_email( 'buyer@' . $domain );
    $new->set_props( $props );
    $orders[] = $new->save();
    return end( $orders );
};

$own_flows = array_merge( WorkflowStore::active_for_trigger( 'order_created' ), WorkflowStore::active_for_trigger( 'order_status_changed' ) );
if ( $own_flows ) {
    WP_CLI::error( 'Deactivate this site\'s Order created and Order status changed workflows first: this check would start them on its orders. Ids: ' . implode( ', ', array_column( $own_flows, 'id' ) ) );
}

if ( ! $posts_store ) {
    // What the option row holds, past every cache; null when there is no row.
    $stored = function () use ( $wpdb, $option ): ?array {
        $value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
        return null === $value ? null : (array) maybe_unserialize( $value );
    };
    $forget = function () use ( $option ) {
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        wp_cache_delete( $option, 'options' );
    };
    $set    = function ( array $value ) use ( $wpdb, $option, $forget ) {
        $wpdb->update( $wpdb->options, [ 'option_value' => maybe_serialize( $value ) ], [ 'option_name' => $option ] );
        $forget();
    };
    $site   = $wpdb->get_col( 'SELECT DISTINCT trigger_id FROM ' . $wpdb->prefix . "yaymatik_workflows WHERE status = 'active'" );

    // Built on read.
    delete_option( $option );
    $read     = WorkflowStore::active_trigger_ids();
    $autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );
    $same     = fn( array $a, array $b ) => ! array_diff( $a, $b ) && ! array_diff( $b, $a );
    $ok( null !== $stored() && $same( $read, $site ) && $same( $stored(), $site ), 'a read with no option builds the list from the active workflows' );
    $ok( in_array( $autoload, wp_autoload_values_to_autoload(), true ), 'the option is autoloaded' );

    // Each writer rewrites the list and never removes it.
    $deletes = 0;
    add_action(
        'delete_option',
        function ( string $name ) use ( $option, &$deletes ) {
            $deletes += $name === $option ? 1 : 0;
        }
    );
    $first = $flow( 'order_created' );
    WorkflowStore::activate( $first );
    $ok( in_array( 'order_created', $stored(), true ), 'activate: the list has the trigger' );
    WorkflowStore::deactivate( $first );
    $ok( ! in_array( 'order_created', $stored(), true ), 'deactivate: the trigger left the list' );
    WorkflowStore::activate( $first );
    WorkflowStore::save_draft( $first, $graph( 'order_status_changed' ) );
    WorkflowStore::apply_draft( $first, $graph( 'order_status_changed' ) );
    $ok( in_array( 'order_status_changed', $stored(), true ) && ! in_array( 'order_created', $stored(), true ), 'apply: a draft on another trigger swaps it in the list' );
    WorkflowStore::deactivate( $first );
    $set( [ 'stale' ] );
    WorkflowStore::delete( $first );
    $ok( $same( $stored(), $site ), 'delete: the list is rewritten' );
    $ok( 0 === $deletes, 'no writer removed the option' );

    // The cached copy can't hide a change: this request still holds the older list when the row changes under it.
    $flow_a = $flow( 'order_created' );
    WorkflowStore::activate( $flow_a );
    $held = get_option( $option );
    WorkflowStore::deactivate( $flow_a );
    wp_cache_set(
        'alloptions',
        array_merge( wp_load_alloptions(), [ $option => maybe_serialize( $held ) ] ),
        'options'
    );
    WorkflowStore::activate( $flow_a );
    $ok( in_array( 'order_created', $stored(), true ), 'a writer whose cached list equals the fresh one still writes the row' );
    WorkflowStore::deactivate( $flow_a );

    // A reader that computed before an Activate can't hide the new trigger.
    delete_option( $option );
    $late = function ( string $query ) use ( &$late, $option, $flow_a ) {
        if ( 0 === strpos( $query, 'INSERT IGNORE' ) && false !== strpos( $query, $option ) ) {
            remove_filter( 'query', $late );
            WorkflowStore::activate( $flow_a );
        }
        return $query;
    };
    add_filter( 'query', $late );
    get_option( $option );
    WorkflowStore::active_trigger_ids();
    $ok( in_array( 'order_created', $stored(), true ), 'a reader that computed before an Activate leaves the new trigger in the list' );
    $forget();
    $ok( in_array( 'order_created', (array) get_option( $option ), true ), 'and the next read in that request sees it' );
    WorkflowStore::deactivate( $flow_a );

    // Second process: `$body` runs holding the lock.
    $flag = wp_tempnam( 'check-catalog' );
    $hold = function ( string $body ) use ( $wpdb, $flag, $option ) {
        wp_delete_file( $flag );
        $lock = $wpdb->prefix . $option;
        $code = 'global $wpdb; $wpdb->get_var( $wpdb->prepare( "SELECT GET_LOCK( %s, 10 )", ' . var_export( $lock, true ) . ' ) ); $ids = $wpdb->get_col( "SELECT DISTINCT trigger_id FROM {$wpdb->prefix}yaymatik_workflows WHERE status = \'active\'" ); touch( ' . var_export( $flag, true ) . ' ); ' . $body; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
        $null = [ 'file', '/dev/null', 'w' ];
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- a dev script on the local site.
        $proc = proc_open(
            'wp --path=' . escapeshellarg( ABSPATH ) . ' eval ' . escapeshellarg( $code ),
            [
                1 => $null,
                2 => $null,
            ],
            $pipes
        );
        $wait = microtime( true ) + 30;
        while ( ! file_exists( $flag ) ) {
            if ( microtime( true ) > $wait ) {
                WP_CLI::error( 'The second process did not take the lock.' );
            }
            usleep( 100000 );
            clearstatcache();
        }
        return $proc;
    };

    // Writer A queries, B's refresh waits, A writes its older list and releases: B's write comes last.
    $flow_b = $flow( 'order_status_changed' );
    $proc   = $hold( 'sleep( 2 ); $wpdb->update( $wpdb->options, [ "option_value" => maybe_serialize( $ids ) ], [ "option_name" => ' . var_export( $option, true ) . ' ] ); $wpdb->get_var( "SELECT RELEASE_LOCK( \'" . $wpdb->prefix . ' . var_export( $option, true ) . ' . "\' )" );' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
    WorkflowStore::activate( $flow_b );
    proc_close( $proc );
    $ok( in_array( 'order_status_changed', $stored(), true ), 'two refreshes interleaved: the later writer\'s trigger stays in the list' );
    WorkflowStore::deactivate( $flow_b );

    // The lock held past 5 seconds: logged, the option removed, the next read rebuilds it.
    $logged = [];
    $log    = function ( $message ) use ( &$logged ) {
        $logged[] = $message;
        return $message;
    };
    add_filter( 'woocommerce_logger_log_message', $log );
    $proc    = $hold( 'sleep( 8 );' );
    $deletes = 0;
    WorkflowStore::refresh_active_triggers();
    remove_filter( 'woocommerce_logger_log_message', $log );
    // The Dispatcher reads the list right after the change, so the row is already rebuilt here.
    $ok( 1 === $deletes, 'lock timeout: the option is removed' );
    $ok( (bool) array_filter( $logged, fn( $message ) => false !== strpos( (string) $message, 'active trigger list' ) ), 'lock timeout: it is logged' );
    proc_close( $proc );
    wp_delete_file( $flag );
    WorkflowStore::activate( $flow_a );
    delete_option( $option );
    $ok( in_array( 'order_created', (array) WorkflowStore::active_trigger_ids(), true ) && in_array( 'order_created', $stored(), true ), 'lock timeout: the next read rebuilds the list' );

    // Cleanup repairs a wrong list.
    $set( [ 'wrong' ] );
    Cleanup::run();
    $ok( in_array( 'order_created', $stored(), true ) && ! in_array( 'wrong', $stored(), true ), 'cleanup recomputes a wrong list' );

    // A fresh request listens only to active triggers: each listener is a closure made in its Trigger.
    $code = 'global $wp_filter; $found = []; foreach ( $wp_filter as $hook ) { foreach ( $hook->callbacks as $callbacks ) { foreach ( $callbacks as $callback ) { if ( $callback["function"] instanceof Closure ) { $owner = ( new ReflectionFunction( $callback["function"] ) )->getClosureThis(); if ( $owner instanceof ' . Trigger::class . ' ) { $found[ $owner->id() ] = true; } } } } } echo wp_json_encode( array_keys( $found ) );';
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- a dev script on the local site.
    $listeners = (array) json_decode( (string) shell_exec( 'wp --path=' . escapeshellarg( ABSPATH ) . ' eval ' . escapeshellarg( $code ) . ' 2>/dev/null' ), true );
    $ok( $same( $listeners, array_merge( $site, [ 'order_created' ] ) ), 'a fresh request has listeners for the active triggers only (' . implode( ', ', $listeners ) . ')' );
    WorkflowStore::deactivate( $flow_a );

    // Activated in this process, after init: the listener is added, and an order starts a run.
    $runs_of = function ( int $workflow_id ) use ( $wpdb ): array {
        $table = RunStore::table();
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE workflow_id = %d", $workflow_id ), ARRAY_A );
    };
    WorkflowStore::activate( $flow_b );
    $status_order = wc_get_order( $order() );
    $status_order->update_status( 'processing' );
    Runtime::dispatcher()->flush();
    $ok( 1 === count( $runs_of( $flow_b ) ), 'same process: activate, then an order event → one run' );
    WorkflowStore::deactivate( $flow_b );
}//end if

// The order meta guard, on this order store.
$meta_step = new UpdateOrderMeta();
$saved     = $order( [ 'total' => '50' ] );
$scope     = new Scope( [ 'trigger' => [ 'order' => new Ref( 'order', (string) $saved ) ] ] );

$blocked = $meta_step->validate(
    [
        'key'   => '_order_total',
        'value' => '1',
    ]
);
$ok( is_wp_error( $blocked ) && 'key' === ( $blocked->get_error_data()['field'] ?? '' ), 'update_order_meta: _order_total is an issue on its field' );
foreach ( [ '_order_total', '_ORDER_TOTAL', '_billing_email', '_yaymatik_order_created', 'wp_vip' ] as $key ) {
    $result = $meta_step->run(
        $scope,
        [
            'key'   => $key,
            'value' => '1',
        ]
    );
    $fresh  = wc_get_order( $saved );
    $ok( StepResult::FAILED === $result->status() && 50.0 === (float) $fresh->get_total() && 'buyer@' . $domain === $fresh->get_billing_email(), "update_order_meta: {$key} fails at run time, the order unchanged" );
}
$result = $meta_step->run(
    $scope,
    [
        'key'   => '_check_catalog',
        'value' => 'one',
    ]
);
$fresh  = wc_get_order( $saved );
$notes  = wc_get_order_notes( [ 'order_id' => $saved ] );
$ok(
    StepResult::OK === $result->status() && 'one' === $fresh->get_meta( '_check_catalog' ) && [
        'key'     => '_check_catalog',
        'changed' => true,
    ] === $result->outputs(),
    'update_order_meta: a normal key writes; the outputs hold no value'
);
$ok( (bool) array_filter( $notes, fn( $note ) => false !== strpos( $note->content, '“” to “one”' ) ), 'update_order_meta: the order note has old and new' );
$again = $meta_step->run(
    $scope,
    [
        'key'   => '_check_catalog',
        'value' => 'one',
    ]
);
$ok( false === $again->outputs()['changed'] && count( $notes ) === count( wc_get_order_notes( [ 'order_id' => $saved ] ) ), 'update_order_meta: the same value reads unchanged, no second note' );

// Rules on a real order, on this order store.
$criteria = new Criteria();
$matches  = fn( array $rule ) => $criteria->evaluate(
    $scope,
    $criteria->sanitize(
        [
            'match' => 'all',
            'rules' => [ $rule + [ 'valueType' => 'value' ] ],
        ]
    )
);
$created  = [ 'field' => 'order.created_date' ];
$ok(
    $matches(
        $created + [
            'operator' => 'in_last',
            'value'    => '2',
            'unit'     => 'days',
        ]
    ),
    'rule: an order made now is in the last 2 days'
);
$ok(
    $matches(
        $created + [
            'operator' => 'in_last',
            'value'    => '1',
            'unit'     => 'hours',
        ]
    ),
    'rule: and in the last hour, read from its real time, not 00:00'
);
$ok(
    ! $matches(
        $created + [
            'operator' => 'not_in_last',
            'value'    => '2',
            'unit'     => 'days',
        ]
    ),
    'rule: and not outside them'
);
$ok(
    $matches(
        $created + [
            'operator' => 'is_set',
            'value'    => '',
        ]
    ),
    'rule: its date is set'
);
$ok(
    $matches(
        [
            'field'    => 'order.status',
            'operator' => 'is_any_of',
            'value'    => [ 'pending', 'processing' ],
        ]
    ),
    'rule: is any of reads its status'
);

if ( $fails ) {
    WP_CLI::error( "{$fails} check(s) failed." );
}
WP_CLI::success( 'All checks passed.' );
