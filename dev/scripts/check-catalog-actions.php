<?php
/**
 * The catalog's new actions on real users, orders and runs, on this order store.
 * Required by check-catalog.php, whose helpers and cleanup it uses.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

use YayMatik\Workflow\Actions\UpdateCustomerMeta;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Steps\StepResult;
use YayMatik\Workflow\Core\Storage\WorkflowStore;

/** The scope of a run about this order, whose customer is its account or its billing email. */
$order_scope = fn( int $order_id ) => new Scope(
    [
        'trigger' => [
            'order'    => new Ref( 'order', (string) $order_id ),
            'customer' => new Ref( 'customer', 'order:' . $order_id ),
        ],
    ]
);
// Workflows run as user 0.
$previous_user = get_current_user_id();
wp_set_current_user( 0 );

// Update customer custom field.
$customer_meta = new UpdateCustomerMeta();
list( $meta_user ) = $new_user( 'meta' );
$meta_order    = $order( [ 'customer_id' => $meta_user ] );
$meta_scope    = $order_scope( $meta_order );
update_user_meta( $meta_user, 'check_vip', 'no' );
$result = $customer_meta->run(
    $meta_scope,
    [
        'key'   => 'check_vip',
        'value' => 'yes {{ order.number }}',
    ]
);
$ok(
    StepResult::OK === $result->status() && 'yes ' . wc_get_order( $meta_order )->get_order_number() === get_user_meta( $meta_user, 'check_vip', true ) && [
        'key'     => 'check_vip',
        'changed' => true,
    ] === $result->outputs(),
    'update_customer_meta: no → yes written as user 0; the outputs hold no value'
);
$result = $customer_meta->run(
    $meta_scope,
    [
        'key'   => 'check_vip',
        'value' => 'yes {{ order.number }}',
    ]
);
$ok( StepResult::OK === $result->status() && false === $result->outputs()['changed'], 'update_customer_meta: the same value reads unchanged' );
$result = $customer_meta->run(
    $meta_scope,
    [
        'key'   => 'check\\_slash',
        'value' => 'a\\b',
    ]
);
$ok( 'a\\b' === get_user_meta( $meta_user, 'check\\_slash', true ), 'update_customer_meta: backslashes in the key and value are kept' );

$guest_result = $customer_meta->run(
    $order_scope( $order() ),
    [
        'key'   => 'check_vip',
        'value' => 'yes',
    ]
);
$ok( StepResult::SKIPPED === $guest_result->status() && 'This customer has no account.' === $guest_result->message(), 'update_customer_meta: a guest order is skipped' );

$caps_key    = $wpdb->base_prefix . 'capabilities';
$caps_before = get_user_meta( $meta_user, $caps_key, true );
$blocked     = [ $caps_key, strtoupper( $caps_key ), $wpdb->base_prefix . 'user_level', $wpdb->base_prefix . '2_capabilities', 'session_tokens', '_yaymatik_claim' ];
foreach ( $blocked as $key ) {
    $refused = $customer_meta->validate(
        [
            'key'   => $key,
            'value' => 'administrator',
        ]
    );
    $result  = $customer_meta->run(
        $meta_scope,
        [
            'key'   => $key,
            'value' => 'administrator',
        ]
    );
    $ok( is_wp_error( $refused ) && 'key' === ( $refused->get_error_data()['field'] ?? '' ) && StepResult::FAILED === $result->status(), "update_customer_meta: {$key} is an issue at save and fails at run time" );
}
// Only the collation links this key to the capabilities row: `user_blocked()` alone lets it through.
$accented  = $wpdb->base_prefix . 'capabilitiés';
$collation = (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", $accented ) );
if ( '0' !== $collation ) {
    $result = $customer_meta->run(
        $meta_scope,
        [
            'key'   => $accented,
            'value' => 'administrator',
        ]
    );
    $ok( StepResult::FAILED === $result->status(), 'update_customer_meta: a key the collation matches to the capabilities row fails' );
} else {
    echo "SKIP the collation here tells {$accented} from {$caps_key}\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
wp_cache_delete( $meta_user, 'user_meta' );
$ok( get_user_meta( $meta_user, $caps_key, true ) === $caps_before && ! user_can( $meta_user, 'manage_options' ), 'update_customer_meta: the capabilities are unchanged afterwards' );

wp_set_current_user( $previous_user );

if ( ! $posts_store ) {
    $admins = get_users(
        [
            'role'   => 'administrator',
            'number' => 1,
            'fields' => 'ID',
        ]
    );
    wp_set_current_user( (int) $admins[0] );
    $writes    = WorkflowStore::create( 'check-catalog', $chain( 'order_created', [], [ [ 'meta', 'update_customer_meta', [ 'key' => 'check_step_key' ] ] ] ) );
    $flows[]   = (int) $writes['id'];
    $suggested = rest_do_request( new WP_REST_Request( 'GET', '/yaymatik/v1/search/customer-meta-keys' ) )->get_data()['keys'];
    $ok( in_array( 'check_step_key', $suggested, true ), 'customer-meta-keys: returns a key an Update customer custom field step writes' );
    wp_set_current_user( $previous_user );
}
