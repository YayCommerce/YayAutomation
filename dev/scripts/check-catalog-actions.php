<?php
/**
 * The catalog's new actions on real users, orders and runs, on this order store.
 * Required by check-catalog.php, whose helpers and cleanup it uses.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

use YayMatik\Workflow\Actions\ChangeCustomerRole;
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
$customer_meta     = new UpdateCustomerMeta();
list( $meta_user ) = $new_user( 'meta' );
$meta_order        = $order( [ 'customer_id' => $meta_user ] );
$meta_scope        = $order_scope( $meta_order );
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

// Change customer role, on check roles removed at the end.
$check_roles = [
    'check_wholesale' => [ 'read' => true ],
    'check_vip'       => [ 'read' => true ],
    'check_helper'    => [
        'read'       => true,
        'edit_users' => true,
    ],
];
foreach ( $check_roles as $slug => $caps ) {
    // A run stopped halfway may have left one behind, with other capabilities.
    remove_role( $slug );
    add_role( $slug, ucfirst( substr( $slug, 6 ) ), $caps );
}
register_shutdown_function(
    function () use ( $check_roles ) {
        foreach ( array_keys( $check_roles ) as $slug ) {
            remove_role( $slug );
        }
    }
);
$role_step = new ChangeCustomerRole();
$listed    = array_keys( ChangeCustomerRole::assignable_roles() );
$ok( ! array_intersect( [ 'administrator', 'shop_manager', 'editor', 'check_helper' ], $listed ) && in_array( 'check_wholesale', $listed, true ) && in_array( 'customer', $listed, true ), 'change_customer_role: the list has no staff role and no role with a protected capability' );
$ok( is_wp_error( $role_step->validate( $role_step->sanitize( [ 'role' => 'administrator' ] ) ) ), 'change_customer_role: Administrator is an issue at save' );
/** A user with these roles and an order of theirs; [ user id, scope ]. */
$role_user = function ( array $roles ) use ( $new_user, $order, $order_scope ): array {
    list( $user_id ) = $new_user( 'role' . wp_rand( 1000, 9999 ) );
    $user            = new WP_User( $user_id );
    $user->set_role( array_shift( $roles ) );
    foreach ( $roles as $role ) {
        $user->add_role( $role );
    }
    return [ $user_id, $order_scope( $order( [ 'customer_id' => $user_id ] ) ) ];
};
$roles_of  = function ( int $user_id ): array {
    clean_user_cache( $user_id );
    $roles = ( new WP_User( $user_id ) )->roles;
    sort( $roles );
    return $roles;
};
$change    = fn( Scope $scope, string $mode, string $role ) => $role_step->run(
    $scope,
    $role_step->sanitize(
        [
            'mode' => $mode,
            'role' => $role,
        ]
    )
);

list( $buyer, $buyer_scope ) = $role_user( [ 'customer' ] );
$result                      = $change( $buyer_scope, 'add', 'check_wholesale' );
$ok( $result->is_ok() && [ 'check_wholesale', 'customer' ] === $roles_of( $buyer ) && 'Customer, Wholesale' === $result->outputs()['to'], 'change_customer_role: add keeps the other role (' . $result->outputs()['to'] . ')' );
$ok( $change( $buyer_scope, 'remove', 'customer' )->is_ok() && [ 'check_wholesale' ] === $roles_of( $buyer ), 'change_customer_role: remove takes one role' );
$result = $change( $buyer_scope, 'remove', 'check_wholesale' );
$ok( $result->is_skipped() && [ 'check_wholesale' ] === $roles_of( $buyer ), 'change_customer_role: the last role is kept (' . $result->message() . ')' );
list( $multi, $multi_scope ) = $role_user( [ 'customer', 'check_vip' ] );
$ok( $change( $multi_scope, 'replace', 'check_wholesale' )->is_ok() && [ 'check_wholesale' ] === $roles_of( $multi ), 'change_customer_role: replace swaps every role for the new one' );
list( $two, $two_scope ) = $role_user( [ 'customer', 'check_vip' ] );
$ok( $change( $two_scope, 'add', 'check_wholesale' )->is_ok() && [ 'check_vip', 'check_wholesale', 'customer' ] === $roles_of( $two ), 'change_customer_role: a user with two roles keeps both on add' );

$families = [
    'administrator'                 => [ 'administrator' ],
    'shop manager'                  => [ 'shop_manager' ],
    'a custom role with edit_users' => [ 'check_helper' ],
];
foreach ( $families as $what => $roles ) {
    list( $staff, $staff_scope ) = $role_user( $roles );
    $before                      = $roles_of( $staff );
    $result                      = $change( $staff_scope, 'replace', 'check_wholesale' );
    $ok( $result->is_skipped() && $before === $roles_of( $staff ), "change_customer_role: {$what} is untouched ({$result->message()})" );
}
list( $direct, $direct_scope ) = $role_user( [ 'customer' ] );
( new WP_User( $direct ) )->add_cap( 'edit_shop_orders' );
$ok( $change( $direct_scope, 'add', 'check_wholesale' )->is_skipped() && [ 'customer' ] === $roles_of( $direct ), 'change_customer_role: a user given a protected capability directly is untouched' );

list( $vendor, $vendor_scope ) = $role_user( [ 'customer' ] );
$grant                         = function ( array $caps, array $asked, array $args ) use ( $vendor ): array {
    return (int) ( $args[1] ?? 0 ) === $vendor ? array_merge( $caps, [ 'edit_shop_orders' => true ] ) : $caps;
};
add_filter( 'user_has_cap', $grant, 10, 3 );
$ok( $change( $vendor_scope, 'replace', 'check_wholesale' )->is_skipped() && [ 'customer' ] === $roles_of( $vendor ), 'change_customer_role: a protected capability a plugin grants through user_has_cap counts too' );
remove_filter( 'user_has_cap', $grant );

get_role( 'check_vip' )->add_cap( 'manage_woocommerce' );
list( $late, $late_scope ) = $role_user( [ 'customer' ] );
$result                    = $role_step->run(
    $late_scope,
    [
        'mode' => 'add',
        'role' => 'check_vip',
    ]
);
$ok( $result->is_failed() && [ 'customer' ] === $roles_of( $late ), 'change_customer_role: a role made protected after the save fails at run time (' . $result->message() . ')' );

$allow                           = fn() => true;
add_filter( ChangeCustomerRole::ALLOW_ALL_FILTER, $allow );
list( $manager, $manager_scope ) = $role_user( [ 'shop_manager' ] );
$ok( $change( $manager_scope, 'add', 'check_vip' )->is_ok() && [ 'check_vip', 'shop_manager' ] === $roles_of( $manager ), 'change_customer_role: the filter allows a protected role and a staff user' );
$ok( $change( $manager_scope, 'remove', 'check_vip' )->is_ok() && $change( $manager_scope, 'remove', 'shop_manager' )->is_skipped(), 'change_customer_role: with the filter on, the last role is still kept' );
remove_filter( ChangeCustomerRole::ALLOW_ALL_FILTER, $allow );

$ok( $change( $order_scope( $order() ), 'add', 'check_wholesale' )->is_skipped(), 'change_customer_role: a guest order is skipped' );
$ok( 0 === get_current_user_id(), 'change_customer_role: all of this ran as user 0' );

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
