<?php
/**
 * Cart tracking on the real carts table, through CartTracker's own methods. Each "browser" is a WooCommerce
 * session handler that is never started (no cookie, nothing saved), keyed `t_check…` or a temporary user's id.
 * Orders are WC_Order objects with a fake id that are never saved, so no order hook or workflow runs.
 * Email capture uses a real WC_Cart holding one temporary hidden product; the classic endpoint's answer is
 * read through the AJAX die handler. Restore calls RestoreCart::restore() (the request handler around it redirects
 * and exits) with more temporary products.
 * Prints PASS / FAIL lines; removes its rows and users. Run through check-carts.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

use YayMatik\Engine\FEPages\RestoreCart;
use YayMatik\Workflow\Core\Cart\CartSnapshot;
use YayMatik\Workflow\Core\Cart\CartTracker;
use YayMatik\Workflow\Core\Cart\EmailCapture;
use YayMatik\Workflow\Core\Cart\RestoreLink;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;
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

// Restore link: RestoreCart::restore() on real products; each visit is a new browser with its own cart.
$simple          = function ( string $name, callable $set = null ) use ( &$posts ) {
    $item = new WC_Product_Simple();
    $item->set_name( 'YayMatik check-carts ' . $name . ' (temporary)' );
    $item->set_catalog_visibility( 'hidden' );
    $item->set_regular_price( '10' );
    if ( $set ) {
        $set( $item );
    }
    $posts[] = $item->save();
    return $item->get_id();
};
$variable        = function () use ( &$posts ) {
    $attribute = new WC_Product_Attribute();
    $attribute->set_name( 'Size' );
    $attribute->set_options( [ 'S', 'M' ] );
    $attribute->set_visible( true );
    $attribute->set_variation( true );
    $parent = new WC_Product_Variable();
    $parent->set_name( 'YayMatik check-carts variable (temporary)' );
    $parent->set_catalog_visibility( 'hidden' );
    $parent->set_attributes( [ $attribute ] );
    $posts[]   = $parent->save();
    $variation = new WC_Product_Variation();
    $variation->set_parent_id( $parent->get_id() );
    $variation->set_attributes( [ 'size' => 'M' ] );
    $variation->set_regular_price( '5' );
    $posts[] = $variation->save();
    return [ $parent->get_id(), $variation->get_id() ];
};
$p1              = $product->get_id();
$p2              = $simple( 'two' );
$p3              = $simple( 'three' );
$oos             = $simple( 'out of stock', fn( $item ) => $item->set_stock_status( 'outofstock' ) );
$low             = $simple(
    'one in stock',
    function ( $item ) {
        $item->set_manage_stock( true );
        $item->set_stock_quantity( 1 );
    }
);
list( $vp, $vv ) = $variable();
// A variation whose parent row is gone: WooCommerce reads it with parent 0.
list( $gone_parent, $orphan ) = $variable();
$wpdb->delete( $wpdb->posts, [ 'ID' => $gone_parent ] );
$wpdb->delete( $wpdb->postmeta, [ 'post_id' => $gone_parent ] );
$wpdb->delete( $wpdb->term_relationships, [ 'object_id' => $gone_parent ] );
clean_post_cache( $gone_parent );
clean_post_cache( $orphan );

$vline = fn( int $parent_id, int $variation, int $quantity ) => [
    'variation_id' => $variation,
    'variation'    => [ 'attribute_size' => 'M' ],
] + $line( $parent_id, $quantity, 5.0 );
/** A saved cart: a row written by its own browser, with an email. */
$saved = function ( string $key, array $lines, int $user_id = 0 ) use ( $browser, $cart, $row, $wpdb, $table ) {
    $browser( $key, $user_id );
    CartTracker::record( $cart( $lines ), 'cart' );
    if ( ! $user_id ) {
        $wpdb->update(
            $table,
            [
                'email'      => 'sam.' . wp_rand( 1000, 9999 ) . '@example.test',
                'first_name' => 'Sam',
            ],
            [ 'session_key' => $key ]
        );
    }
    return $row( $key );
};
$link  = RestoreLink::from_options();
$token = function ( array $r ) use ( $link ) {
    parse_str( (string) wp_parse_url( $link->url( (int) $r['id'], $r['created_at'] ), PHP_URL_QUERY ), $args );
    return $args[ UnsubscribeLink::ARG_TOKEN ];
};
/** A new browser holding these cart lines; with $own its cart is also recorded as its own row. */
$visitor    = function ( string $key, array $lines = [], int $user_id = 0, bool $own = false ) use ( $browser ) {
    $browser( $key, $user_id );
    WC()->customer = new WC_Customer( $user_id, true );
    WC()->cart->empty_cart( false );
    foreach ( $lines as $l ) {
        WC()->cart->add_to_cart( $l['product_id'], $l['quantity'], $l['variation_id'], $l['variation'] );
    }
    WC()->cart->calculate_totals();
    if ( $own ) {
        CartTracker::record( CartSnapshot::from_wc(), 'cart' );
    }
};
$restore    = fn( array $r, string $tok = null ) => RestoreCart::get_instance()->restore( (int) $r['id'], $tok ?? $token( $r ) );
$notices    = fn( string $type ) => array_column( (array) wc_get_notices( $type ), 'notice' );
$in_cart    = function () {
    $quantities = [];
    foreach ( WC()->cart->get_cart() as $item ) {
        $id                = (int) ( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
        $quantities[ $id ] = ( $quantities[ $id ] ?? 0 ) + (int) $item['quantity'];
    }
    ksort( $quantities );
    return $quantities;
};
$checkout   = wc_get_checkout_url();
$shop_url   = wc_get_page_permalink( 'shop' );
$gone_text  = 'This cart is no longer available.';
$added_text = 'We added your saved items to your cart.';
$some_text  = 'Some items are no longer available.';

$s = $saved( $prefix . 's', [ $line( $p1, 1 ), $line( $p2, 2 ) ] );
$ok( $link->verify( (int) $s['id'], $s['created_at'], $token( $s ) ), 'restore URL verifies against its row' );
$tok   = $token( $s );
$other = ( 'A' === $tok[0] ? 'B' : 'A' ) . substr( $tok, 1 );
$ok( ! $link->verify( (int) $s['id'], $s['created_at'], $other ) && ! $link->verify( (int) $s['id'] + 1, $s['created_at'], $tok ), 'restore URL: a changed token or another id fails' );

// 1. Gone or bad token.
$visitor( $prefix . 'v1' );
$url = $restore(
    [
        'id'         => 999999999,
        'created_at' => $s['created_at'],
    ]
);
$ok( $shop_url === $url && [ $gone_text ] === $notices( 'notice' ) && null === WC()->session->get( 'yaymatik_restore_notice' ), 'outcome 1, row gone: shop + "no longer available", no checkout notice flag' );
$visitor( $prefix . 'v1b' );
$url = $restore( $s, $other );
$ok( $shop_url === $url && [ $gone_text ] === $notices( 'notice' ) && [] === $in_cart(), 'outcome 1, bad token: shop + "no longer available", nothing added' );
$cycle = $saved( $prefix . 'cycle', [ $line( $p1, 1 ) ] );
$age( $prefix . 'cycle', "created_at = '{$old}', status = 'ordered'" );
$cycle     = $row( $prefix . 'cycle' );
$cycle_tok = $token( $cycle );
CartStore::reset_cycle( (int) $cycle['id'] );
$visitor( $prefix . 'v1c' );
$url = $restore( $cycle, $cycle_tok );
$ok( $shop_url === $url && [ $gone_text ] === $notices( 'notice' ), "outcome 1, a new cycle's created_at: the old link fails" );

// 2. Ordered.
$o2 = $saved( $prefix . 'o2', [ $line( $p1, 1 ) ] );
$wpdb->update( $table, [ 'status' => 'ordered' ], [ 'id' => (int) $o2['id'] ] );
$visitor( $prefix . 'v2' );
$url = $restore( $o2 );
$ok( $shop_url === $url && [ 'You already placed an order for these items.' ] === $notices( 'notice' ) && [] === $in_cart(), 'outcome 2, ordered: shop + "already placed an order", nothing added' );

// 3. Every line unavailable, a variation with a deleted parent among them.
$u3 = $saved( $prefix . 'u3', [ $line( $oos, 1 ), $vline( $gone_parent, $orphan, 1 ) ] );
$visitor( $prefix . 'v3' );
$url = $restore( $u3 );
$ok( $shop_url === $url && [ $gone_text ] === $notices( 'notice' ) && [] === $in_cart(), 'outcome 3, all unavailable (out of stock, variation with a deleted parent): shop + "no longer available", no fatal' );

// 4. Everything already in the cart.
$visitor( $prefix . 'v4', [ $line( $p1, 1 ), $line( $p2, 3 ) ] );
$before = $in_cart();
$url    = $restore( $s );
$ok( $checkout === $url && [] === (array) wc_get_notices() && $before === $in_cart(), 'outcome 4, all already in the cart: checkout, no notice, cart unchanged' );
$ok( null === WC()->session->get( 'yaymatik_restore_notice' ), 'outcome 4 without a notice: no checkout notice flag' );
$u4 = $saved( $prefix . 'u4', [ $line( $p1, 1 ), $line( $oos, 1 ) ] );
$visitor( $prefix . 'v4b', [ $line( $p1, 1 ) ] );
$before = $in_cart();
$url    = $restore( $u4 );
$ok( $checkout === $url && [] === $notices( 'success' ) && [ $some_text ] === $notices( 'notice' ) && $before === $in_cart(), 'outcome 4 with unavailable lines: checkout + "some no longer available" only, cart unchanged' );
$ok( true === WC()->session->get( 'yaymatik_restore_notice' ), 'outcome 4 with a notice: checkout notice flag set' );

// 5. Added, without and with skipped lines.
$s_before = $row( $prefix . 's' );
$v5       = $prefix . 'v5';
$visitor( $v5 );
$url = $restore( $s );
$ok( $checkout === $url && [ $added_text ] === $notices( 'success' ) && [] === $notices( 'notice' ), 'outcome 5: checkout + "added", no skipped notice' );
$ok( true === WC()->session->get( 'yaymatik_restore_notice' ), 'outcome 5: checkout notice flag set' );
$ok(
    [
        $p1 => 1,
        $p2 => 2,
    ] === $in_cart(),
    'outcome 5: both saved lines in the cart'
);
$ok( WC()->customer->get_billing_email() === $s['email'] && 'Sam' === WC()->customer->get_billing_first_name(), "outcome 5, guest's empty cart: email and first name prefilled" );
$ok( $row( $prefix . 's' ) === $s_before && null === $row( $v5 ), 'restore writes nothing: saved row unchanged, no row for the visitor' );
$ok( WC()->session->get( 'yaymatik_cart_hash' ) === CartSnapshot::from_wc()->hash() && (int) WC()->session->get( 'yaymatik_restored_cart' ) === (int) $s['id'], "restore: merged cart's hash and the restored id in the session" );
CartTracker::record( CartSnapshot::from_wc(), 'cart' );
$ok( $row( $prefix . 's' ) === $s_before && null === $row( $v5 ), 'next request, cart unchanged: still no write, no binding' );

$block = '<div class="wp-block-woocommerce-checkout"></div>';
$first = apply_filters( 'render_block_woocommerce/checkout', $block, [] );
$ok( 1 === substr_count( $first, $added_text ) && 0 === strpos( $first, '<div class="woocommerce-notices-wrapper">' ) && null === WC()->session->get( 'yaymatik_restore_notice' ) && [] === (array) wc_get_notices(), 'Checkout block: the notice printed once before the block, flag and notices cleared' );
$ok( apply_filters( 'render_block_woocommerce/checkout', $block, [] ) === $block, 'Checkout block, next render: nothing added' );

$mix = $saved( $prefix . 'mix', [ $line( $p1, 1 ), $line( $oos, 1 ), $vline( $gone_parent, $orphan, 1 ), $vline( $vp, $vv, 2 ) ] );
$visitor( $prefix . 'v5b' );
$url = $restore( $mix );
$ok(
    $checkout === $url && [ $added_text ] === $notices( 'success' ) && [ $some_text ] === $notices( 'notice' ) && [
        $p1 => 1,
        $vv => 2,
    ] === $in_cart(),
    'outcome 5 with skipped lines: "added" + "some no longer available", the rest added'
);

$visitor( $prefix . 'v5c', [ $line( $p2, 5 ) ] );
$restore( $s );
$ok(
    [
        $p1 => 1,
        $p2 => 5,
    ] === $in_cart(),
    'merge: the larger quantity wins, missing line added'
);

// Notices: earlier ones kept, ones added while merging dropped.
$refuse = function ( $passed, $product_id ) use ( $p2 ) {
    if ( $p2 === (int) $product_id ) {
        wc_add_notice( 'Refused by a filter.', 'error' );
        return false;
    }
    return $passed;
};
$n      = $saved( $prefix . 'n', [ $line( $p1, 1 ), $line( $p2, 1 ), $line( $low, 3 ) ] );
$visitor( $prefix . 'v6' );
wc_add_notice( 'An earlier notice.', 'error' );
add_filter( 'woocommerce_add_to_cart_validation', $refuse, 10, 2 );
$restore( $n );
remove_filter( 'woocommerce_add_to_cart_validation', $refuse, 10 );
$ok( [ 'An earlier notice.' ] === $notices( 'error' ) && [ $added_text ] === $notices( 'success' ) && [ $some_text ] === $notices( 'notice' ) && [ $p1 => 1 ] === $in_cart(), "notices: the earlier one kept; the filter's and WooCommerce's stock errors dropped; refused lines skipped" );

// Prefill only into an empty guest checkout with a guest's cart.
$visitor( $prefix . 'v7', [ $line( $p3, 1 ) ] );
$restore( $s );
$ok( '' === WC()->customer->get_billing_email(), 'no prefill when the cart had items' );
$l7 = $user( 'restorer' );
$visitor( (string) $l7, [], $l7 );
$restore( $s );
// A logged-in customer's session already holds the account email.
$ok(
    WC()->customer->get_billing_email() !== $s['email'] && 'Sam' !== WC()->customer->get_billing_first_name() && [
        $p1 => 1,
        $p2 => 2,
    ] === $in_cart(),
    'logged-in visitor: items added, no prefill'
);
$wpdb->delete( $table, [ 'session_key' => (string) $l7 ] );

// Binding.
$b  = $saved( $prefix . 'b', [ $line( $p1, 1 ), $line( $p2, 1 ) ] );
$vb = $prefix . 'vb';
$visitor( $vb, [ $line( $p3, 1 ) ], 0, true );
$own = (int) $row( $vb )['id'];
$restore( $b );
$ok( $own === (int) $row( $vb )['id'] && $row( $prefix . 'b' )['session_key'] === $prefix . 'b', 'binding: nothing moves on the restore itself' );
WC()->cart->add_to_cart( $p3, 1 );
WC()->cart->calculate_totals();
CartTracker::record( CartSnapshot::from_wc(), 'cart' );
$r = $row( $vb );
$ok( $r && (int) $r['id'] === (int) $b['id'] && null !== $r['restored_at'] && 3 === count( json_decode( $r['items'], true ) ) && null === $row( $prefix . 'b' ), 'binding: the first change moves the restored row to this browser, restored_at set, new items' );
$ok( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $own ) ) && null === WC()->session->get( 'yaymatik_restored_cart' ), "binding: the browser's own row deleted, session var cleared" );

$uo  = $user( 'owner' );
$urw = $saved( (string) $uo, [ $line( $p1, 1 ) ], $uo );
$vu  = $prefix . 'vu';
$visitor( $vu );
$restore( $urw );
WC()->cart->add_to_cart( $p3, 1 );
WC()->cart->calculate_totals();
CartTracker::record( CartSnapshot::from_wc(), 'cart' );
$ok( $row( (string) $uo )['session_key'] === (string) $uo && null === $row( (string) $uo )['restored_at'] && $row( $vu ) && (int) $row( $vu )['id'] !== (int) $urw['id'], "a user's row is never bound to a guest; the guest gets its own row" );
$ok( '' === WC()->customer->get_billing_email(), "a user's cart restored by a guest: no prefill" );

$bo = $saved( $prefix . 'bo', [ $line( $p1, 1 ) ] );
$vo = $prefix . 'vo';
$visitor( $vo );
$restore( $bo );
$wpdb->update( $table, [ 'status' => 'ordered' ], [ 'id' => (int) $bo['id'] ] );
WC()->cart->add_to_cart( $p3, 1 );
WC()->cart->calculate_totals();
CartTracker::record( CartSnapshot::from_wc(), 'cart' );
$ok( $row( $prefix . 'bo' ) && $row( $vo ) && (int) $row( $vo )['id'] !== (int) $bo['id'], 'a row ordered before the first change is not bound' );

$bp     = $saved( $prefix . 'bp', [ $line( $p1, 1 ) ] );
$vp_key = $prefix . 'vp';
$visitor( $vp_key, [ $line( $p3, 1 ) ], 0, true );
$wpdb->update( $table, [ 'order_id' => $fake + 3 ], [ 'session_key' => $vp_key ] );
$restore( $bp );
WC()->cart->add_to_cart( $p3, 1 );
WC()->cart->calculate_totals();
CartTracker::record( CartSnapshot::from_wc(), 'cart' );
$ok( (int) $row( $vp_key )['order_id'] === $fake + 3 && $row( $prefix . 'bp' )['session_key'] === $prefix . 'bp', "own row linked to an unpaid order: kept, the restored row isn't bound" );

$visitor( $prefix . 've' );
$restore( $s );
CartTracker::emptied();
$ok( null === WC()->session->get( 'yaymatik_restored_cart' ), 'emptied clears the restored id' );

$settings( [ 'cart_tracking' => false ] + $on );
$bt = $saved( $prefix . 'bt', [ $line( $p1, 1 ) ] );
$vt = $prefix . 'vt';
$visitor( $vt );
$url = $restore( $bt );
$ok( $checkout === $url && [ $p1 => 1 ] === $in_cart() && null === WC()->session->get( 'yaymatik_restored_cart' ), 'tracking off: items restored, no restored id kept (never binds)' );
$settings( $on );
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
