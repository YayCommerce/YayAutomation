<?php
/**
 * Cart tracking on the real carts table, through CartTracker's own methods. Each "browser" is a WooCommerce
 * session handler that is never started (no cookie, nothing saved), keyed `t_check…` or a temporary user's id.
 * Orders are WC_Order objects with a fake id that are never saved, so no order hook or workflow runs.
 * Email capture uses a real WC_Cart holding one temporary hidden product; the classic endpoint's answer is
 * read through the AJAX die handler.
 * Prints PASS / FAIL lines; removes its rows and users. Run through check-carts.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

use YayMatik\Workflow\Core\Cart\CartSnapshot;
use YayMatik\Workflow\Core\Cart\CartTracker;
use YayMatik\Workflow\Core\Cart\EmailCapture;
use YayMatik\Workflow\Core\Storage\CartStore;
use YayMatik\Workflow\Core\Storage\Schema;
use YayMatik\Workflow\Core\Storage\Settings;

if ( ! function_exists( 'WC' ) ) {
    WP_CLI::error( 'WooCommerce is not active.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;
$table  = Schema::table( Schema::CARTS );
$prefix = 't_check' . wp_rand( 1000, 9999 ) . '_';
$users  = [];
$posts  = [];

$fails = 0;
$ok    = function ( bool $pass, string $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $fails += $pass ? 0 : 1;
};

$cleanup = function () use ( $wpdb, $table, $prefix, &$users, &$posts ) {
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE session_key LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
    foreach ( $posts as $post_id ) {
        wp_delete_post( $post_id, true );
    }
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

// Email capture. Settings come from a filter, so the stored option never changes.
$stored   = Settings::all();
$settings = function ( array $changes ) use ( $stored ) {
    remove_all_filters( 'pre_option_yaymatik_settings' );
    add_filter( 'pre_option_yaymatik_settings', fn() => array_merge( $stored, $changes ) );
    $built = new ReflectionProperty( Settings::class, 'all' );
    $built->setAccessible( true );
    $built->setValue( null, null );
};
$on       = [
    'cart_tracking'       => true,
    'capture_guest_email' => true,
];
$settings( $on );
unset( $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand( 1, 254 );

$product = new WC_Product_Simple();
$product->set_name( 'YayMatik check-carts (temporary)' );
// Published (a guest can't buy a private product) but hidden from the shop and search.
$product->set_catalog_visibility( 'hidden' );
$product->set_regular_price( '10' );
$posts[]       = $product->save();
WC()->customer = new WC_Customer( 0, true );
WC()->cart     = new WC_Cart();
/** A guest browser with the product in its cart and a session cookie. */
$shop = function ( string $key ) use ( $browser, $product ) {
    $browser( $key );
    $cookie = new ReflectionProperty( WC_Session_Handler::class, '_has_cookie' );
    $cookie->setAccessible( true );
    $cookie->setValue( WC()->session, true );
    WC()->cart->empty_cart( false );
    WC()->cart->add_to_cart( $product->get_id(), 1 );
};
/** The classic endpoint's body. */
$post   = function ( string $email, array $server = [ 'HTTP_SEC_FETCH_SITE' => 'same-origin' ] ) {
    unset( $_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'] );
    $_SERVER = array_merge( $_SERVER, $server );
    $_POST   = [
        'email'      => $email,
        'first_name' => 'Gina',
    ];
    $doing   = fn() => true;
    $die     = function () {
        return function () {
            throw new UnexpectedValueException( 'wp_die' );
        };
    };
    add_filter( 'wp_doing_ajax', $doing );
    add_filter( 'wp_die_ajax_handler', $die );
    ob_start();
    try {
        EmailCapture::ajax();
    } catch ( UnexpectedValueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- wp_send_json() ends with wp_die().
    }
    remove_filter( 'wp_doing_ajax', $doing );
    remove_filter( 'wp_die_ajax_handler', $die );
    return (string) ob_get_clean();
};
$bodies = [];

$r1 = $user( 'registered' );
$browser( (string) $r1, $r1 );
CartTracker::record( $cart( [ $line( 9, 1 ) ] ), 'cart' );
$registered = $row( (string) $r1 );

$c1 = $prefix . 'c1';
$shop( $c1 );
$new      = 'Gina.' . wp_rand( 1000, 9999 ) . '@Example.test';
$bodies[] = $post( $new );
$r        = $row( $c1 );
$ok( $r && strtolower( $new ) === $r['email'] && 'Gina' === $r['first_name'] && null !== $r['checkout_at'] && 0 === (int) $r['user_id'], 'classic capture: email lowercased, first name, checkout_at; user_id 0' );

$bodies[] = $post( strtoupper( $registered['email'] ) );
$r        = $row( $c1 );
$ok( $registered['email'] === $r['email'] && 0 === (int) $r['user_id'], "a registered customer's email: kept on the guest row, user_id 0" );
$ok( $row( (string) $r1 ) === $registered, "a registered customer's email: the customer's own row untouched" );

$age( $c1, "updated_at = '{$old}'" );
$bodies[] = $post( 'not-an-email' );
$ok( $old === $row( $c1 )['updated_at'], 'invalid email: row unchanged' );
$bodies[] = $post( 'cross@example.test', [ 'HTTP_SEC_FETCH_SITE' => 'cross-site' ] );
$ok( $old === $row( $c1 )['updated_at'], 'Sec-Fetch-Site cross-site: refused, row unchanged' );
$bodies[] = $post( 'origin@example.test', [ 'HTTP_ORIGIN' => 'https://evil.example' ] );
$ok( $old === $row( $c1 )['updated_at'], 'Origin of another site: refused, row unchanged' );
$logged = 0;
$count  = function ( $message ) use ( &$logged ) {
    $logged += 'Email capture refused: not from the store' === $message ? 1 : 0;
    return $message;
};
delete_transient( 'yaymatik_log_not_from_the_store' );
add_filter( 'woocommerce_logger_log_message', $count );
$post( 'cross1@example.test', [ 'HTTP_SEC_FETCH_SITE' => 'cross-site' ] );
$first = $logged;
$post( 'cross2@example.test', [ 'HTTP_SEC_FETCH_SITE' => 'cross-site' ] );
remove_filter( 'woocommerce_logger_log_message', $count );
$ok( $first > 0 && $first === $logged && false !== get_transient( 'yaymatik_log_not_from_the_store' ), 'two cross-site requests: logged once, flag set for the hour' );
$logged = 0;
add_filter( 'woocommerce_logger_log_message', $count );
$post( 'not-an-email' );
remove_filter( 'woocommerce_logger_log_message', $count );
$ok( 0 === $logged, 'invalid email: nothing logged' );

$settings( [ 'capture_guest_email' => false ] + $on );
$bodies[] = $post( 'off@example.test' );
$ok( $old === $row( $c1 )['updated_at'], 'capture off: row unchanged' );
$settings( [ 'cart_tracking' => false ] + $on );
$bodies[] = $post( 'tracking-off@example.test' );
$ok( $old === $row( $c1 )['updated_at'], 'tracking off: row unchanged' );
$settings( $on );
wp_set_current_user( $r1 );
$bodies[] = $post( 'loggedin@example.test' );
$ok( $row( (string) $r1 ) === $registered && $old === $row( $c1 )['updated_at'], 'logged in: refused, no row changed' );
wp_set_current_user( 0 );
$ok( [ '{"success":true}' ] === array_values( array_unique( $bodies ) ) && 8 === count( $bodies ), 'classic endpoint: the same body for new, registered, invalid, cross-site, other origin, capture off, tracking off, logged in' );

$d = 'dee.' . wp_rand( 1000, 9999 ) . '@example.test';
foreach ( [ 'c2', 'c3', 'c4', 'c5' ] as $name ) {
    $shop( $prefix . $name );
    $post( $d );
}
$ok( 3 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email = %s", $d ) ) && '' === (string) ( $row( $prefix . 'c5' )['email'] ?? '' ), 'one email from at most 3 sessions: the fourth is dropped' );

$c6 = $prefix . 'c6';
$shop( $c6 );
$customer = new WC_Customer();
$customer->set_billing_email( 'Eve.' . wp_rand( 1000, 9999 ) . '@Example.test' );
$customer->set_billing_first_name( 'Eve' );
EmailCapture::store_api( $customer, null );
$r = $row( $c6 );
$ok( strtolower( $customer->get_billing_email() ) === $r['email'] && 'Eve' === $r['first_name'] && null !== $r['checkout_at'], 'block checkout: email and first name captured, checkout_at set' );
$age( $c6, "updated_at = '{$old}'" );
$customer->set_billing_city( 'Hanoi' );
EmailCapture::store_api( $customer, null );
$ok( $old === $row( $c6 )['updated_at'], 'block checkout, same email and name again: nothing written' );
$customer->set_billing_first_name( 'Evelyn' );
EmailCapture::store_api( $customer, null );
$ok( 'Evelyn' === $row( $c6 )['first_name'], 'block checkout, name changed: captured again' );
remove_all_filters( 'pre_option_yaymatik_settings' );

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
