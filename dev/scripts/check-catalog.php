<?php
/**
 * The custom workflow catalog on the real tables and real orders at a random `.test` domain, through the real Dispatcher.
 * Runs stay queued, their jobs removed, except the group's, which go through the real Worker and write order notes. Two checks start a second wp-cli process, as GET_LOCK is re-entrant in one session.
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
use YayMatik\Workflow\Core\Data\CustomerStats;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Definition\GraphSchema;
use YayMatik\Workflow\Core\Definition\Messages;
use YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Core\Runtime\Cleanup;
use YayMatik\Workflow\Core\Runtime\Runtime;
use YayMatik\Workflow\Core\Steps\Trigger;
use YayMatik\Workflow\Core\Steps\StepResult;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\WorkflowStore;
use YayMatik\Workflow\DataItems\CustomerData;

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
$posts  = [];
$terms  = [];
$users  = [];

$fails = 0;
$ok    = function ( bool $pass, string $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $fails += $pass ? 0 : 1;
};

$cleanup = function () use ( $wpdb, &$flows, &$orders, &$posts, &$terms, &$users ) {
    foreach ( $posts as $post_id ) {
        wp_delete_post( $post_id, true );
    }
    foreach ( $terms as $term_id ) {
        wp_delete_term( $term_id, 'product_cat' );
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ( $users as $user_id ) {
        wp_delete_user( $user_id );
    }
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

if ( ! $posts_store ) {
    // REST search: paging, include, and who may call it.
    $admins = get_users(
        [
            'role'   => 'administrator',
            'number' => 1,
            'fields' => 'ID',
        ]
    );
    wp_set_current_user( (int) $admins[0] );
    $word   = 'chk' . wp_rand( 100000, 999999 );
    $ids    = [];
    $cat_id = [];
    $codes  = [];
    for ( $i = 0; $i < 21; $i++ ) {
        $posts[]  = wp_insert_post(
            [
                'post_type'   => 'product',
                'post_status' => 'publish',
                'post_title'  => "{$word} product {$i}",
            ]
        );
        $ids[]    = end( $posts );
        $term     = wp_insert_term( "{$word} category {$i}", 'product_cat' );
        $terms[]  = $term['term_id'];
        $cat_id[] = $term['term_id'];
        $posts[]  = wp_insert_post(
            [
                'post_type'   => 'shop_coupon',
                'post_status' => 'publish',
                'post_title'  => "{$word}code{$i}",
            ]
        );
        $codes[]  = "{$word}code{$i}";
    }
    $search = function ( string $type, array $params ) {
        $request = new WP_REST_Request( 'GET', "/yaymatik/v1/search/{$type}" );
        $request->set_query_params( $params );
        return rest_do_request( $request );
    };
    foreach ( [
        'products'   => $ids,
        'categories' => $cat_id,
        'coupons'    => $codes,
    ] as $type => $values ) {
        $first  = $search( $type, [ 'term' => $word ] );
        $second = $search(
            $type,
            [
                'term' => $word,
                'page' => 2,
            ]
        );
        $data   = $first->get_data();
        $last   = $second->get_data();
        $ok( 200 === $first->get_status() && 20 === count( $data['items'] ) && true === $data['hasMore'], "search {$type}: a full first page says there is more" );
        $ok( 1 === count( $last['items'] ) && false === $last['hasMore'], "search {$type}: the last page says there isn't" );
        $ok( is_string( $data['items'][0]['value'] ) && is_string( $data['items'][0]['label'] ), "search {$type}: items hold value and label" );
        $picked = (string) $values[3];
        $exact  = $search( $type, [ 'include' => [ $picked ] ] )->get_data();
        $ok( 1 === count( $exact['items'] ) && $picked === $exact['items'][0]['value'] && false === $exact['hasMore'], "search {$type}: include returns exactly that item" );
    }
    $parent_cat = wp_insert_term( "{$word} Men & Boys", 'product_cat' );
    $child_cat  = wp_insert_term( "{$word} Shoes", 'product_cat', [ 'parent' => $parent_cat['term_id'] ] );
    array_push( $terms, $child_cat['term_id'], $parent_cat['term_id'] );
    $path = $search( 'categories', [ 'include' => [ (string) $child_cat['term_id'] ] ] )->get_data();
    $ok( "{$word} Men & Boys › {$word} Shoes" === $path['items'][0]['label'], 'search categories: a label shows its parents, with no entities' );
    $found = $search( 'categories', [ 'term' => 'Men & Boys' ] )->get_data();
    $ok( in_array( (string) $parent_cat['term_id'], array_column( $found['items'], 'value' ), true ), 'search categories: a name with & is found' );
    $gone = $search( 'products', [ 'include' => [ '999999999' ] ] )->get_data();
    $ok( [] === $gone['items'], 'search products: include of a deleted id returns nothing, so the form shows #12' );
    $bad = $search( 'orders', [] );
    $ok( 404 === $bad->get_status(), 'search: an unknown type is a 404' );

    $meta_key  = 'chkkey' . wp_rand( 1000, 9999 );
    $meta_flow = WorkflowStore::create(
        'check-catalog',
        GraphSchema::prepare(
            [
                'nodes' => [
                    [
                        'id'       => 'trigger',
                        'kind'     => 'trigger',
                        'position' => [
                            'x' => 0,
                            'y' => 0,
                        ],
                        'data'     => [
                            'block'  => 'order_created',
                            'config' => [],
                        ],
                    ],
                    [
                        'id'       => 'rule',
                        'kind'     => 'condition',
                        'position' => [
                            'x' => 0,
                            'y' => 100,
                        ],
                        'data'     => [
                            'block'  => 'condition',
                            'config' => [
                                'match' => 'all',
                                'rules' => [
                                    [
                                        'field'     => 'customer.meta',
                                        'key'       => $meta_key,
                                        'operator'  => 'equal',
                                        'value'     => 'yes',
                                        'valueType' => 'value',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'edges' => [
                    [
                        'id'     => 'e',
                        'source' => 'trigger',
                        'target' => 'rule',
                    ],
                ],
            ]
        )
    );
    $flows[]   = (int) $meta_flow['id'];
    $meta_keys = rest_do_request( new WP_REST_Request( 'GET', '/yaymatik/v1/search/customer-meta-keys' ) );
    $ok( 200 === $meta_keys->get_status() && in_array( $meta_key, $meta_keys->get_data()['keys'], true ), 'customer-meta-keys: returns a key used in a rule' );
    $protected                     = $graph( 'order_created' );
    $with_rule                     = $protected;
    $with_rule['nodes'][1]['kind'] = 'condition';
    $with_rule['nodes'][1]['data'] = [
        'block'  => 'condition',
        'config' => [
            'match' => 'all',
            'rules' => [
                [
                    'field'     => 'customer.meta',
                    'key'       => 'session_tokens',
                    'operator'  => 'is_empty',
                    'value'     => '',
                    'valueType' => 'value',
                ],
            ],
        ],
    ];
    $blocked_flow                  = WorkflowStore::create( 'check-catalog', $with_rule );
    $flows[]                       = (int) $blocked_flow['id'];
    $meta_keys                     = rest_do_request( new WP_REST_Request( 'GET', '/yaymatik/v1/search/customer-meta-keys' ) );
    $ok( ! in_array( 'session_tokens', $meta_keys->get_data()['keys'], true ), 'customer-meta-keys: a protected key saved in a draft is not suggested' );

    $shopper = wp_create_user( 'shopper_' . wp_rand( 100000, 999999 ), wp_generate_password(), 'shopper@' . $domain );
    $users[] = $shopper;
    ( new WP_User( $shopper ) )->set_role( 'customer' );
    wp_set_current_user( $shopper );
    foreach ( [ 'products', 'categories', 'coupons' ] as $type ) {
        $ok( 403 === $search( $type, [ 'term' => $word ] )->get_status(), "search {$type}: a customer gets a 403" );
    }
    $ok( 403 === rest_do_request( new WP_REST_Request( 'GET', '/yaymatik/v1/search/customer-meta-keys' ) )->get_status(), 'customer-meta-keys: a customer gets a 403' );
    wp_set_current_user( 0 );
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

// A group, through the real Dispatcher and Worker, down each branch.
$note_node   = fn( string $id, int $y ) => [
    'id'       => $id,
    'kind'     => 'action',
    'position' => [
        'x' => 0,
        'y' => $y,
    ],
    'data'     => [
        'block'  => 'add_order_note',
        'config' => [
            'note' => "check-catalog {$id}",
            'type' => 'private',
        ],
    ],
];
$branch_flow = WorkflowStore::create(
    'check-catalog',
    GraphSchema::prepare(
        [
            'nodes' => [
                [
                    'id'       => 'trigger',
                    'kind'     => 'trigger',
                    'position' => [
                        'x' => 0,
                        'y' => 0,
                    ],
                    'data'     => [
                        'block'  => 'order_created',
                        'config' => [],
                    ],
                ],
                [
                    'id'       => 'rule',
                    'kind'     => 'condition',
                    'position' => [
                        'x' => 0,
                        'y' => 100,
                    ],
                    'data'     => [
                        'block'  => 'condition',
                        'config' => [
                            'match' => 'any',
                            'rules' => [
                                [
                                    'match' => 'all',
                                    'rules' => [
                                        [
                                            'field'     => 'order.total',
                                            'operator'  => 'greater_or_equal',
                                            'value'     => '100',
                                            'valueType' => 'value',
                                        ],
                                        [
                                            'field'     => 'order.status',
                                            'operator'  => 'is_any_of',
                                            'value'     => [ 'pending', 'on-hold' ],
                                            'valueType' => 'value',
                                        ],
                                    ],
                                ],
                                [
                                    'field'     => 'order.total',
                                    'operator'  => 'equal',
                                    'value'     => '7',
                                    'valueType' => 'value',
                                ],
                            ],
                        ],
                    ],
                ],
                $note_node( 'yes', 200 ),
                $note_node( 'no', 300 ),
            ],
            'edges' => [
                [
                    'id'     => 'e1',
                    'source' => 'trigger',
                    'target' => 'rule',
                ],
                [
                    'id'           => 'e2',
                    'source'       => 'rule',
                    'target'       => 'yes',
                    'sourceHandle' => 'then',
                ],
                [
                    'id'           => 'e3',
                    'source'       => 'rule',
                    'target'       => 'no',
                    'sourceHandle' => 'otherwise',
                ],
            ],
        ]
    )
);
if ( ! is_array( $branch_flow ) ) {
    WP_CLI::error( 'Could not create the group workflow.' );
}
$flows[] = (int) $branch_flow['id'];
$ok( true === WorkflowStore::activate( (int) $branch_flow['id'] ), 'group: a condition with a group activates' );
$path_of = function ( array $props ) use ( $order, $branch_flow, $wpdb ): string {
    $before = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . RunStore::table() );
    $made   = $order( $props );
    Runtime::dispatcher()->flush();
    $run_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . RunStore::table() . ' WHERE id > %d AND workflow_id = %d', $before, (int) $branch_flow['id'] ) );
    foreach ( $run_ids as $run_id ) {
        as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ (int) $run_id ], ActionSchedulerQueue::GROUP );
        Runtime::worker()->run( (int) $run_id );
    }
    $notes = wp_list_pluck( wc_get_order_notes( [ 'order_id' => $made ] ), 'content' );
    return implode( ',', array_filter( [ in_array( 'check-catalog yes', $notes, true ) ? 'yes' : '', in_array( 'check-catalog no', $notes, true ) ? 'no' : '' ] ) );
};
$ok( 'yes' === $path_of( [ 'total' => '150' ] ), 'group: both rules in the group match → Yes' );
$ok( 'no' === $path_of( [ 'total' => '50' ] ), 'group: one rule in it fails, and the rule beside it too → No' );
$ok( 'yes' === $path_of( [ 'total' => '7' ] ), 'group: the group fails, the rule beside it matches → Yes' );
WorkflowStore::deactivate( (int) $branch_flow['id'] );

// Customer stats and fields, on this order store.
$email = 'stats@' . $domain;
$user  = wp_create_user( 'stats_' . wp_rand( 100000, 999999 ), wp_generate_password(), $email );
if ( ! is_int( $user ) ) {
    WP_CLI::error( 'Could not create a temporary user.' );
}
$users[] = $user;
$totals  = fn() => CustomerStats::totals( (int) $user, $email );
$paid    = function ( string $total, array $props = [], string $status = 'processing' ) use ( $order, $email ): WC_Order {
    $made = wc_get_order( $order( $props + [ 'billing_email' => $email ] ) );
    $made->set_total( $total );
    $made->set_status( $status );
    $made->save();
    return $made;
};
$ok( 0 === $totals()['count'] && 0.0 === $totals()['spent'] && null === $totals()['last_paid'], 'stats: nothing paid yet' );
$paid( '100', [ 'customer_id' => (int) $user ] );
$guest_paid = $paid( '50', [ 'date_created' => time() - DAY_IN_SECONDS ] );
$ok( 2 === $totals()['count'] && 150.0 === $totals()['spent'], 'stats: an account order and a guest order with its email, each counted once' );
$paid( '999', [], 'pending' );
$paid( '999', [], 'failed' );
$ok( 2 === $totals()['count'] && 150.0 === $totals()['spent'], 'stats: pending and failed don\'t count' );
$refund = wc_create_refund(
    [
        'order_id' => $guest_paid->get_id(),
        'amount'   => '20',
    ]
);
$ok( ! is_wp_error( $refund ) && 130.0 === $totals()['spent'] && 2 === $totals()['count'], 'stats: a refund is subtracted, the order still counts' );
$paid( '10', [ 'currency' => 'EUR' ] );
$ok( 3 === $totals()['count'] && 140.0 === $totals()['spent'], 'stats: a second currency is summed as stored' );
$ok( $totals()['last_paid'] > time() - HOUR_IN_SECONDS, 'stats: last_paid is the newest paid order' );
$ok( 0 === CustomerStats::totals( 0, '' )['count'], 'stats: no account and no email is zeros' );

$term_parent = wp_insert_term( 'check-catalog parent ' . $domain, 'product_cat' );
$term_child  = wp_insert_term( 'check-catalog child ' . $domain, 'product_cat', [ 'parent' => $term_parent['term_id'] ] );
$terms       = [ $term_child['term_id'], $term_parent['term_id'] ];
$bought      = new WC_Product_Simple();
$bought->set_name( 'check-catalog ' . $domain );
$bought->set_category_ids( [ $term_child['term_id'] ] );
$bought_id = $bought->save();
$posts[]   = $bought_id;
$coupon    = new WC_Coupon();
$coupon->set_code( 'chk' . wp_rand( 100000, 999999 ) );
$coupon->set_amount( 1 );
$posts[] = $coupon->save();
$rich    = $paid( '30', [ 'customer_id' => (int) $user ] );
$rich->add_product( wc_get_product( $bought_id ), 1 );
$rich->apply_coupon( $coupon->get_code() );
$rich->set_billing_country( 'US' );
$rich->update_meta_data( '_check_tier', '5.0' );
$rich->save();
update_user_meta( (int) $user, 'check_tier', 'gold' );

$rich_scope = new Scope( [ 'trigger' => [ 'order' => new Ref( 'order', (string) $rich->get_id() ) ] ] );
$rich_rule  = fn( array $rule ) => $criteria->evaluate(
    $rich_scope,
    $criteria->sanitize(
        [
            'match' => 'all',
            'rules' => [ $rule + [ 'valueType' => 'value' ] ],
        ]
    )
);
$item_scan  = 0;
$count_scan = function ( string $query ) use ( &$item_scan ) {
    $item_scan += false !== strpos( $query, 'woocommerce_order_itemmeta' ) ? 1 : 0;
    return $query;
};
add_filter( 'query', $count_scan );
$customer_scope = new Scope( [ 'trigger' => [ 'customer' => new Ref( 'customer', 'order:' . $rich->get_id() ) ] ] );
$customer_rule  = fn( array $rule ) => $criteria->evaluate(
    $customer_scope,
    $criteria->sanitize(
        [
            'match' => 'all',
            'rules' => [ $rule + [ 'valueType' => 'value' ] ],
        ]
    )
);
$ok(
    $customer_rule(
        [
            'field'    => 'customer.paid_order_count',
            'operator' => 'equal',
            'value'    => '4',
        ]
    ),
    'rule: paid order count reads the stats'
);
$scan_before    = $item_scan;
$ok(
    $customer_rule(
        [
            'field'    => 'customer.purchased_product_ids',
            'operator' => 'matches_any',
            'value'    => [ (string) $bought_id ],
        ]
    ),
    'rule: products purchased reads the bought product'
);
$ok( $item_scan > $scan_before, 'and only that field scans order items' );
remove_filter( 'query', $count_scan );
$ok( 0 === $scan_before, 'stats alone never scan order items' );

$ok(
    $rich_rule(
        [
            'field'    => 'order.product_ids',
            'operator' => 'matches_all',
            'value'    => [ (string) $bought_id ],
        ]
    ),
    'rule: order products match'
);
$ok(
    $rich_rule(
        [
            'field'    => 'order.category_ids',
            'operator' => 'matches_any',
            'value'    => [ (string) $term_parent['term_id'] ],
        ]
    ),
    'rule: a parent category matches through its child'
);
$ok(
    $rich_rule(
        [
            'field'    => 'order.coupon_codes',
            'operator' => 'matches_any',
            'value'    => [ strtoupper( $coupon->get_code() ) ],
        ]
    ),
    'rule: a coupon matches, ignoring case'
);
$ok(
    $rich_rule(
        [
            'field'    => 'order.billing_country',
            'operator' => 'is_any_of',
            'value'    => [ 'US', 'CA' ],
        ]
    ),
    'rule: billing country is any of'
);
$ok(
    $rich_rule(
        [
            'field'    => 'order.meta',
            'key'      => '_check_tier',
            'operator' => 'equal',
            'value'    => '5',
        ]
    ),
    'rule: order.meta compares "5.0" as the number 5'
);
$ok(
    $customer_rule(
        [
            'field'    => 'customer.meta',
            'key'      => 'check_tier',
            'operator' => 'equal',
            'value'    => 'gold',
        ]
    ),
    'rule: customer.meta reads the account'
);
$ok(
    $customer_rule(
        [
            'field'    => 'customer.role',
            'operator' => 'matches_any',
            'value'    => [ get_option( 'default_role', 'subscriber' ) ],
        ]
    ),
    'rule: customer.role reads the account'
);
$ok(
    $customer_rule(
        [
            'field'    => 'customer.registered_date',
            'operator' => 'in_last',
            'value'    => '1',
            'unit'     => 'hours',
        ]
    ),
    'rule: registered in the last hour, from its real time'
);
$ok(
    $customer_rule(
        [
            'field'    => 'customer.last_order_date',
            'operator' => 'in_last',
            'value'    => '1',
            'unit'     => 'hours',
        ]
    ),
    'rule: last paid order in the last hour'
);

$refused = $criteria->validate(
    $criteria->sanitize(
        [
            'match' => 'all',
            'rules' => [
                [
                    'field'     => 'customer.meta',
                    'key'       => 'session_tokens',
                    'operator'  => 'is_not_empty',
                    'value'     => '',
                    'valueType' => 'value',
                ],
            ],
        ]
    )
);
$ok( is_wp_error( $refused ) && Messages::get( 'ruleMetaKey' ) === $refused->get_error_message(), 'rule: session_tokens is refused' );
$ok( '' === CustomerData::custom_field( new WC_Customer( (int) $user ), 'session_tokens' ), 'and reads empty if a rule gets through' );
$real_prefix = $wpdb->base_prefix . 'capabilities';
$refused     = $criteria->validate(
    $criteria->sanitize(
        [
            'match' => 'all',
            'rules' => [
                [
                    'field'     => 'customer.meta',
                    'key'       => strtoupper( $real_prefix ),
                    'operator'  => 'is_not_empty',
                    'value'     => '',
                    'valueType' => 'value',
                ],
            ],
        ]
    )
);
$ok( is_wp_error( $refused ), 'rule: this site\'s capabilities key is refused, in any case' );

if ( $fails ) {
    WP_CLI::error( "{$fails} check(s) failed." );
}
WP_CLI::success( 'All checks passed.' );
