<?php
/**
 * Cart tracking on the real carts table, through CartTracker's own methods. Each "browser" is a WooCommerce
 * session handler that is never started (no cookie, nothing saved), keyed `t_check…` or a temporary user's id.
 * Orders are WC_Order objects with a fake id that are never saved, so no order hook or workflow runs.
 * Prints PASS / FAIL lines; removes its rows and users. Run through check-carts.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

use YayMatik\Workflow\Core\Cart\CartSnapshot;
use YayMatik\Workflow\Core\Cart\CartTracker;
use YayMatik\Workflow\Core\Storage\CartStore;
use YayMatik\Workflow\Core\Storage\Schema;

if ( ! function_exists( 'WC' ) ) {
    WP_CLI::error( 'WooCommerce is not active.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;
$table  = Schema::table( Schema::CARTS );
$prefix = 't_check' . wp_rand( 1000, 9999 ) . '_';
$users  = [];

$fails = 0;
$ok    = function ( bool $pass, string $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $fails += $pass ? 0 : 1;
};

$cleanup = function () use ( $wpdb, $table, $prefix, &$users ) {
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE session_key LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
    foreach ( $users as $user_id ) {
        $wpdb->delete( $table, [ 'session_key' => (string) $user_id ] );
        wp_delete_user( $user_id );
    }
};
// Runs even when a check throws or PHP dies, so no test rows are left behind.
register_shutdown_function( $cleanup );

$ok( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'carts table exists (DB v' . get_option( 'yaymatik_db_version' ) . ')' );

/** A browser: a fresh, unstarted WooCommerce session with this key. */
$browser = function ( string $key, int $user_id = 0 ) {
    $session = new WC_Session_Handler();
    $id      = new ReflectionProperty( WC_Session::class, '_customer_id' );
    $id->setAccessible( true );
    $id->setValue( $session, $key );
    WC()->session = $session;
    wp_set_current_user( $user_id );
};
$user    = function ( string $name, string $first_name = '' ) use ( &$users ) {
    $id      = wp_insert_user(
        [
            'user_login' => 'yaymatik_check_' . $name . wp_rand( 1000, 9999 ),
            'user_email' => 'Check-' . $name . wp_rand( 1000, 9999 ) . '@Example.test',
            'user_pass'  => wp_generate_password(),
            'first_name' => $first_name,
        ]
    );
    $users[] = $id;
    return $id;
};
$line    = fn( int $product_id, int $quantity, float $price = 10.0 ) => [
    'product_id'   => $product_id,
    'variation_id' => 0,
    'quantity'     => $quantity,
    'variation'    => [],
    'name'         => 'Check product ' . $product_id,
    'price'        => $price,
    'line_total'   => $price * $quantity,
];
$cart    = fn( array $lines ) => CartSnapshot::from_lines( $lines, array_sum( array_column( $lines, 'line_total' ) ), 'USD' );
$row     = fn( string $key ) => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_key = %s", $key ), ARRAY_A );
$age     = function ( string $key, string $columns ) use ( $wpdb, $table ) {
    $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$columns} WHERE session_key = %s", $key ) );
};
$order   = function ( int $id, string $email, float $total, string $created = 'now' ) {
    $order = new WC_Order();
    $order->set_id( $id );
    $order->set_billing_email( $email );
    $order->set_billing_first_name( 'Orderer' );
    $order->set_total( $total );
    $order->set_date_created( strtotime( $created ) );
    return $order;
};
// A new request: CartTracker's per-request flags (cart touched, order created) cleared.
$next_request = function () {
    foreach ( [ 'touched', 'ordered' ] as $flag ) {
        $property = new ReflectionProperty( CartTracker::class, $flag );
        $property->setAccessible( true );
        $property->setValue( null, false );
    }
};
$old          = '2001-01-01 00:00:00';

// Write and hash skip.
$a = $prefix . 'a';
$browser( $a );
CartTracker::record( $cart( [ $line( 1, 1 ) ] ), 'cart' );
$r = $row( $a );
$ok( $r && 'active' === $r['status'] && 1 === count( json_decode( $r['items'], true ) ) && '' === $r['email'] && 0 === (int) $r['user_id'] && 10.0 === (float) $r['total'], 'guest cart: one active row, 1 line, no email' );

$age( $a, "updated_at = '{$old}'" );
CartTracker::record( $cart( [ $line( 1, 1 ) ] ), 'cart' );
$ok( $old === $row( $a )['updated_at'], 'same lines: nothing written' );
CartTracker::record( $cart( [ $line( 1, 1, 12.0 ) ] ), 'cart' );
$r = $row( $a );
$ok( $old === $r['updated_at'] && 10.0 === (float) $r['total'], 'price change only: nothing written' );
CartTracker::record( $cart( [ $line( 1, 3 ) ] ), 'cart' );
$r = $row( $a );
$ok( $old !== $r['updated_at'] && 30.0 === (float) $r['total'] && 3 === json_decode( $r['items'], true )[0]['quantity'], 'quantity change: row updated, new total' );

// Logged in, the first request opens checkout: record() sets checkout_at, an order-received page doesn't.
$c = $user( 'checkout' );
$browser( (string) $c, $c );
add_filter( 'woocommerce_is_checkout', '__return_true' );
add_filter( 'woocommerce_is_order_received_page', '__return_true' );
CartTracker::record( $cart( [ $line( 8, 1 ) ] ), 'cart' );
$ok( null === $row( (string) $c )['checkout_at'], 'logged in, order-received page: checkout_at stays empty' );
remove_filter( 'woocommerce_is_order_received_page', '__return_true' );
CartTracker::record( $cart( [ $line( 8, 2 ) ] ), 'cart' );
$ok( null !== $row( (string) $c )['checkout_at'], 'logged in, first write on checkout: checkout_at set' );
$g2 = $prefix . 'g2';
$browser( $g2 );
CartTracker::record( $cart( [ $line( 8, 1 ) ] ), 'cart' );
$ok( null === $row( $g2 )['checkout_at'], 'guest on checkout: checkout_at stays empty (set by email capture)' );
remove_filter( 'woocommerce_is_checkout', '__return_true' );

// Emptied.
$browser( $a );
CartTracker::emptied();
$ok( null === $row( $a ), 'emptied without an order: row deleted' );

// Login: the guest row replaces the user's older row and takes the account email.
$u = $user( 'login', 'Ann' );
$browser( (string) $u, $u );
CartTracker::record( $cart( [ $line( 2, 1 ) ] ), 'cart' );
$older = (int) $row( (string) $u )['id'];
$g     = $prefix . 'g';
$browser( $g );
CartTracker::record( $cart( [ $line( 3, 2 ) ] ), 'cart' );
$guest_id = (int) $row( $g )['id'];
wp_set_current_user( $u );
CartTracker::login( $g, (string) $u );
$r = $row( (string) $u );
$ok( null === $row( $g ) && $r && (int) $r['id'] === $guest_id && (int) $r['user_id'] === $u, 'login: guest row moved to the user id' );
$ok( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $older ) ), "login: the user's older row is gone" );
$ok( $r && strtolower( get_userdata( $u )->user_email ) === $r['email'] && 'Ann' === $r['first_name'], 'login: account email (lowercased) and first name' );
$ok( ! CartStore::rekey( $prefix . 'none', $u, 'x@example.test', '' ) && (int) $row( (string) $u )['id'] === $guest_id, 'login without a guest row: nothing changes' );

// Ordered by order_id, then a new cycle.
$fake = 990000000 + wp_rand( 1000, 9999 );
$d    = $prefix . 'd';
$browser( $d );
CartTracker::record( $cart( [ $line( 4, 1 ) ] ), 'cart' );
$o = $order( $fake, 'Dee@Example.test', 10.0 );
CartTracker::order_created( $o );
$r = $row( $d );
$ok( (int) $r['order_id'] === $fake && null !== $r['checkout_at'] && 'dee@example.test' === $r['email'] && 'Orderer' === $r['first_name'] && 'active' === $r['status'], 'order created: order_id, checkout_at, email filled; still active' );
$next_request();
CartTracker::emptied();
$ok( null !== $row( $d ), 'emptied in a later request: row with an order kept' );
CartTracker::order_status_changed( $fake, 'pending', 'on-hold', $o );
$ok( 'ordered' === $row( $d )['status'], 'paid (on-hold): row with that order_id ordered' );

$age( $d, "created_at = '{$old}', restored_at = '{$old}'" );
CartTracker::record( $cart( [ $line( 5, 1 ) ] ), 'cart' );
$r = $row( $d );
$ok( 'active' === $r['status'] && $old !== $r['created_at'] && null === $r['checkout_at'] && 0 === (int) $r['order_id'] && null === $r['restored_at'] && 5 === json_decode( $r['items'], true )[0]['product_id'], 'ordered cart changed: new cycle, fields cleared, new items' );

// Ordered by email: carts last active before the paid order, never for a free order.
$x  = 'check-x' . wp_rand( 1000, 9999 ) . '@example.test';
$e1 = $prefix . 'e1';
$e2 = $prefix . 'e2';
foreach ( [ $e1, $e2 ] as $key ) {
    $browser( $key );
    CartTracker::record( $cart( [ $line( 6, 1 ) ] ), 'cart' );
    $wpdb->update( $table, [ 'email' => $x ], [ 'session_key' => $key ] );
}
$age( $e1, 'last_activity_at = UTC_TIMESTAMP() - INTERVAL 2 HOUR' );
CartTracker::order_status_changed( $fake + 1, 'pending', 'processing', $order( $fake + 1, strtoupper( $x ), 0.0, '-1 hour' ) );
$ok( 'active' === $row( $e1 )['status'], 'free order: carts with the email untouched' );
CartTracker::order_status_changed( $fake + 1, 'pending', 'processing', $order( $fake + 1, strtoupper( $x ), 25.0, '-1 hour' ) );
$ok( 'ordered' === $row( $e1 )['status'], 'paid order: active cart with the email, last active before it, ordered' );
$ok( 'active' === $row( $e2 )['status'], 'paid order: a cart active after the order was placed untouched' );
CartTracker::order_status_changed( $fake + 2, 'pending', 'pending', $order( $fake + 2, $x, 25.0 ) );
$ok( 'active' === $row( $e2 )['status'], 'unpaid status: nothing marked' );

// Logout last: did_action( 'wp_logout' ) stays true for the rest of this process.
$l = $user( 'logout' );
$browser( (string) $l, $l );
CartTracker::record( $cart( [ $line( 7, 2 ) ] ), 'cart' );
$before = $row( (string) $l );
$next_request();
do_action( 'wp_logout', $l );
CartTracker::emptied();
$after = $row( (string) $l );
$ok( $after && $after['items'] === $before['items'] && $after['status'] === $before['status'], 'logout empties the cart: row kept, same items and status' );

WC()->session = null;
echo $fails ? "{$fails} failed\n" : "All passed\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
exit( $fails ? 1 : 0 );
