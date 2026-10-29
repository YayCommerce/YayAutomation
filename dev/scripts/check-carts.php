<?php
/**
 * Cart tracking on the real carts table, through CartTracker's own methods. Each "browser" is a WooCommerce
 * session handler that is never started (no cookie, nothing saved), keyed `t_check…` or a temporary user's id.
 * Orders are WC_Order objects with a fake id that are never saved, so no order hook or workflow runs.
 * Email capture uses a real WC_Cart holding one temporary hidden product; the classic endpoint's answer is
 * read through the AJAX die handler. Restore calls RestoreCart::restore() (the request handler around it redirects
 * and exits) with more temporary products. Cleanup and the privacy exporter / eraser run on temporary runs and
 * emails at a random `.test` domain; cleanup also deletes the site's own runs and carts past the longest retention.
 * Prints PASS / FAIL lines; removes its rows and users. Run through check-carts.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

use YayMatik\Engine\FEPages\RestoreCart;
use YayMatik\Engine\Privacy\PersonalData;
use YayMatik\Workflow\Core\Cart\CartSnapshot;
use YayMatik\Workflow\Core\Cart\CartTracker;
use YayMatik\Workflow\Core\Cart\EmailCapture;
use YayMatik\Workflow\Core\Cart\RestoreLink;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;
use YayMatik\Workflow\Core\Runtime\Cleanup;
use YayMatik\Workflow\Core\Storage\CartStore;
use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\Schema;
use YayMatik\Workflow\Core\Storage\Settings;
use YayMatik\Workflow\Core\Storage\UnsubscribeStore;

if ( ! function_exists( 'WC' ) ) {
    WP_CLI::error( 'WooCommerce is not active.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;
$table  = Schema::table( Schema::CARTS );
$prefix = 't_check' . wp_rand( 1000, 9999 ) . '_';
$users  = [];
$posts  = [];
$runs   = [];
$orders = [];
$domain = 'x' . wp_rand( 1000, 9999 ) . '.test';

$fails = 0;
$ok    = function ( bool $pass, string $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $fails += $pass ? 0 : 1;
};

$cleanup = function () use ( $wpdb, $table, $prefix, $domain, &$users, &$posts, &$runs, &$orders ) {
    foreach ( $orders as $order_id ) {
        $saved_order = wc_get_order( $order_id );
        if ( $saved_order ) {
            $saved_order->delete( true );
        }
    }
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE session_key LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
    if ( $runs ) {
        RunStore::delete_with_steps( $runs );
    }
    $unsubscribes = Schema::table( Schema::UNSUBSCRIBES );
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$unsubscribes} WHERE email LIKE %s", '%@' . $wpdb->esc_like( $domain ) . '%' ) );
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

// Cleanup: the longest retention, so only test rows dated 2001 and the site's own very old rows qualify.
$settings(
    $on + [
        'run_retention_days'  => 730,
        'cart_retention_days' => 365,
    ]
);
$runs_table  = RunStore::table();
$steps_table = RunStepStore::table();
/** A run (and its steps) finished in 2001, of no workflow and its own root unless given. */
$new_run  = function ( array $run, array $steps = [] ) use ( $wpdb, $runs_table, $steps_table, $old, &$runs ) {
    $wpdb->insert(
        $runs_table,
        $run + [
            'workflow_id' => 0,
            'version_id'  => 0,
            'status'      => 'completed',
            'scope'       => '{}',
            'started_at'  => $old,
            'finished_at' => $old,
        ]
    );
    $id     = (int) $wpdb->insert_id;
    $runs[] = $id;
    if ( empty( $run['root_run_id'] ) ) {
        $wpdb->update( $runs_table, [ 'root_run_id' => $id ], [ 'id' => $id ] );
    }
    foreach ( $steps as $step ) {
        $wpdb->insert(
            $steps_table,
            $step + [
                'run_id'     => $id,
                'step_id'    => 'check',
                'status'     => 'completed',
                'started_at' => $old,
            ]
        );
    }
    return $id;
};
$run_row  = fn( int $id ) => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$runs_table} WHERE id = %d", $id ), ARRAY_A );
$step_row = fn( int $id ) => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$steps_table} WHERE run_id = %d", $id ), ARRAY_A );
$workflow = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Schema::table( Schema::WORKFLOWS ) );

$done_old   = $new_run( [ 'workflow_id' => $workflow ], [ [], [] ] );
$deleted_wf = $new_run( [ 'status' => 'failed' ] );
$open       = [];
foreach ( [ 'queued', 'running', 'waiting' ] as $status ) {
    $open[] = $new_run( [ 'status' => $status ] );
}
$done_new = $new_run( [ 'finished_at' => gmdate( 'Y-m-d H:i:s', time() - 700 * DAY_IN_SECONDS ) ] );
$saved( $prefix . 'old', [ $line( 1, 1 ) ] );
$age( $prefix . 'old', "last_activity_at = '{$old}'" );
$saved( $prefix . 'fresh', [ $line( 1, 1 ) ] );
$age( $prefix . 'fresh', "last_activity_at = '" . gmdate( 'Y-m-d H:i:s', time() - 364 * DAY_IN_SECONDS ) . "'" );
Cleanup::run();
$ok( null === $run_row( $done_old ) && null === $step_row( $done_old ), 'cleanup: an old finished run and its steps deleted' );
$ok( null === $run_row( $deleted_wf ), 'cleanup: an old run of a deleted workflow deleted' );
$ok( 3 === count( array_filter( array_map( $run_row, $open ) ) ), 'cleanup: queued, running and waiting runs kept, even with an old finished_at' );
$ok( null !== $run_row( $done_new ), 'cleanup: a finished run inside the window kept' );
$ok( null === $row( $prefix . 'old' ) && null !== $row( $prefix . 'fresh' ), 'cleanup: an old cart deleted, one inside the window kept' );

// Carts go before any run query, so a run backlog that uses the whole budget can't keep an expired cart.
$saved( $prefix . 'old', [ $line( 1, 1 ) ] );
$age( $prefix . 'old', "last_activity_at = '{$old}'" );
$queries = [];
$record  = function ( $sql ) use ( &$queries, $table, $runs_table ) {
    if ( 0 === strpos( $sql, "DELETE FROM {$table} " ) ) {
        $queries[] = 'carts';
    } elseif ( false !== strpos( $sql, "FROM {$runs_table} r WHERE" ) ) {
        $queries[] = 'runs';
    }
    return $sql;
};
add_filter( 'query', $record );
Cleanup::run();
remove_filter( 'query', $record );
$ok( null === $row( $prefix . 'old' ) && 'carts' === ( $queries[0] ?? '' ) && in_array( 'runs', $queries, true ), 'cleanup: expired carts deleted before the first run query (' . implode( ', ', $queries ) . ')' );
$parent = $new_run( [] );
$child  = $new_run(
    [
        'status'        => 'waiting',
        'finished_at'   => null,
        'parent_run_id' => $parent,
        'root_run_id'   => $parent,
        'depth'         => 1,
    ]
);
Cleanup::run();
$ok( null !== $run_row( $parent ), 'cleanup: an old finished parent kept while its child waits' );
$wpdb->update(
    $runs_table,
    [
        'status'      => 'completed',
        'finished_at' => current_time( 'mysql', true ),
    ],
    [ 'id' => $child ]
);
Cleanup::run();
$ok( null === $run_row( $parent ) && null !== $run_row( $child ), 'cleanup: the parent deleted once its child finished; the child, inside the window, kept' );

// Schedule ensure. Leaves the job scheduled and the option fresh, as on any page load.
$pending = fn() => as_get_scheduled_actions(
    [
        'hook'     => Cleanup::HOOK,
        'status'   => 'pending',
        'per_page' => -1,
    ]
);
as_unschedule_all_actions( Cleanup::HOOK );
update_option( Cleanup::CHECKED_OPTION, time() - 120, true );
Cleanup::ensure();
$ok( [] === $pending(), 'ensure: checked under an hour ago, nothing scheduled' );
delete_option( Cleanup::CHECKED_OPTION );
Cleanup::ensure();
Cleanup::ensure();
$jobs     = $pending();
$job      = reset( $jobs );
$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Cleanup::CHECKED_OPTION ) );
$ok( 1 === count( $jobs ) && 'yaymatik' === $job->get_group() && DAY_IN_SECONDS === (int) $job->get_schedule()->get_recurrence(), 'ensure twice within the hour: one daily job in the yaymatik group' );
$ok( abs( time() - (int) get_option( Cleanup::CHECKED_OPTION ) ) < 60 && in_array( $autoload, [ 'yes', 'on' ], true ), 'ensure: checked time saved, autoloaded' );

// Privacy: export and erase.
$privacy = PersonalData::get_instance();
$bob     = "bob@{$domain}";
$jim     = "jimbob@{$domain}";
$au      = "bob@{$domain}.au";
$json    = fn( array $value ) => wp_json_encode( $value );
$all     = $new_run(
    [
        'scope' => $json(
            [
                'email' => $bob,
                'other' => $jim,
            ]
        ),
        'error' => "Mail to {$bob} failed",
    ],
    [
        [
            'input'  => $json( [ 'to' => $bob ] ),
            'output' => $json( [ 'to' => $bob ] ),
            'error'  => "{$bob} bounced",
        ],
    ]
);
$others  = $new_run(
    [
        'scope' => $json(
            [
                'a'    => $jim,
                'b'    => $au,
                'note' => "Write to {$bob} today",
            ]
        ),
        'error' => "{$jim}, {$au}",
    ],
    [
        [
            'input'  => $json( [ 'to' => $jim ] ),
            'output' => $json( [ 'to' => $au ] ),
            'error'  => "{$jim} {$au}",
        ],
    ]
);
$mixed   = $new_run( [ 'scope' => $json( [ 'email' => 'Bob@' . ucfirst( $domain ) ] ) ] );
$run_err = $new_run( [ 'error' => "Bounce from {$bob}. Also {$bob}.au." ] );
$step_er = $new_run( [], [ [ 'error' => "Could not send to <{$bob}>" ] ] );
$step_in = $new_run( [], [ [ 'input' => $json( [ 'to' => $bob ] ) ] ] );
$cart_b  = $saved( $prefix . 'bob', [ $line( $p1, 1 ) ] );
$wpdb->update( $table, [ 'email' => $bob ], [ 'id' => (int) $cart_b['id'] ] );
UnsubscribeStore::add( $bob );

$export = $privacy->export( strtoupper( $bob ), 1 );
$items  = array_column( $export['data'], 'data', 'item_id' );
$ok(
    $export['done'] && array_keys( $items ) === [ 'yaymatik-cart-' . $cart_b['id'], 'yaymatik-unsubscribe', "yaymatik-run-{$all}", "yaymatik-run-{$mixed}", "yaymatik-run-{$run_err}", "yaymatik-run-{$step_er}", "yaymatik-run-{$step_in}" ],
    'export: the cart, the unsubscribe record and every run erase would change (error texts, a step input only), in one page'
);
$ok( in_array( $bob, array_column( $items[ "yaymatik-run-{$all}" ], 'value' ), true ) && ! in_array( 'Sent to', array_column( $items[ "yaymatik-run-{$mixed}" ], 'name' ), true ) && 'Deleted workflow' === $items[ "yaymatik-run-{$all}" ][0]['value'], 'export: "sent to" only where a step sent to it; a deleted workflow named so' );
$ok(
    [
        'data' => [],
        'done' => true,
    ] === $privacy->export( 'not-an-email', 1 ),
    'export: not an email → nothing, done'
);

$before_all   = $run_row( $all );
$before_other = [ $run_row( $others ), $step_row( $others ) ];
$erased       = $privacy->erase( $bob, 1 );
$after_all    = $run_row( $all );
$after_step   = $step_row( $all );
$ok( $erased['done'] && $erased['items_removed'] && $erased['items_retained'] && [ 'The unsubscribe record is kept so this address gets no more workflow emails.' ] === $erased['messages'], 'erase: removed, retained with the unsubscribe note, done' );
$ok( null === $row( $prefix . 'bob' ) && UnsubscribeStore::has( $bob ), 'erase: the cart deleted, the unsubscribe record kept' );
$ok(
    false !== strpos( $after_all['scope'], '"email":"[deleted]"' ) && false !== strpos( $after_all['scope'], $jim ) && 'Mail to [deleted] failed' === $after_all['error']
        && $json( [ 'to' => '[deleted]' ] ) === $after_step['input'] && $json( [ 'to' => '[deleted]' ] ) === $after_step['output'] && '[deleted] bounced' === $after_step['error'],
    'erase: [deleted] in the five columns, the other address kept'
);
$kept        = fn( array $r ) => [ $r['status'], $r['started_at'], $r['finished_at'] ];
$ok( $kept( $before_all ) === $kept( $after_all ), 'erase: status and dates kept' );
$after_other = [ $run_row( $others ), $step_row( $others ) ];
$ok( $before_other === $after_other, "erase {$bob}: jimbob@, …test.au and a mention inside a longer JSON string untouched in all five columns" );
$ok( '{"email":"[deleted]"}' === $run_row( $mixed )['scope'], 'erase: a mixed-case stored email erased' );
$ok( "Bounce from [deleted]. Also {$bob}.au." === $run_row( $run_err )['error'] && 'Could not send to <[deleted]>' === $step_row( $step_er )['error'], 'erase: a run whose only mention is in an error text erased (run and step error); a sentence-final dot still matches, .au not' );
$ok( [] === RunStore::ids_mentioning( $bob, [], 50 ), 'erase: nothing left that mentions the email' );
$visitor( $prefix . 'vb' );
$url = $restore( $cart_b );
$ok( $shop_url === $url && [ $gone_text ] === $notices( 'notice' ), 'erase: the erased cart\'s restore link gives outcome 1' );

$axb    = $new_run(
    [
        'scope' => $json( [ 'email' => "axb@{$domain}" ] ),
        'error' => "axb@{$domain}",
    ]
);
$before = $run_row( $axb );
$erased = $privacy->erase( "a_b@{$domain}", 1 );
$ok( $before === $run_row( $axb ) && ! $erased['items_removed'] && $erased['done'], 'erase a_b@: axb@ untouched (esc_like)' );

// Open runs: a waiting one is cancelled, then anonymized; a running one is left until it ends.
$op      = "open@{$domain}";
$w_run   = $new_run(
    [
        'status'      => 'waiting',
        'finished_at' => null,
        'wait_until'  => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
        'scope'       => $json( [ 'email' => $op ] ),
    ],
    [
        [
            'status' => 'waiting',
            'input'  => $json( [ 'to' => $op ] ),
        ],
    ]
);
$r_run   = $new_run(
    [
        'status'      => 'running',
        'finished_at' => null,
        'scope'       => $json( [ 'email' => $op ] ),
    ]
);
$erased  = $privacy->erase( $op, 1 );
$w_after = $run_row( $w_run );
$w_step  = $step_row( $w_run );
$reason  = "The customer's personal data was erased.";
$ok(
    'cancelled' === $w_after['status'] && $reason === $w_after['error'] && null !== $w_after['finished_at'] && '{"email":"[deleted]"}' === $w_after['scope']
        && 'cancelled' === $w_step['status'] && $reason === $w_step['error'] && $json( [ 'to' => '[deleted]' ] ) === $w_step['input'],
    'erase: a waiting run and its waiting step cancelled with the erase reason, then anonymized'
);
$ok(
    $json( [ 'email' => $op ] ) === $run_row( $r_run )['scope'] && $erased['done'] && $erased['items_retained'] && [ 'A workflow run is in progress; erase again in a few minutes.' ] === $erased['messages'],
    'erase: a running run left as is; retained with the in-progress message, done'
);
$wpdb->update(
    $runs_table,
    [
        'status'      => 'completed',
        'finished_at' => current_time( 'mysql', true ),
    ],
    [ 'id' => $r_run ]
);
$erased = $privacy->erase( $op, 1 );
$ok( '{"email":"[deleted]"}' === $run_row( $r_run )['scope'] && [] === $erased['messages'] && $erased['done'], 'erase again once it ended: anonymized, no message' );

// Runs about the person's orders and carts: the scope holds refs, never the email.
/** A saved pending order; no trigger sees it (its woocommerce_new_order callbacks are set aside). */
$saved_order = function ( string $email ) use ( &$orders ) {
    global $wp_filter;
    $hooks = $wp_filter['woocommerce_new_order'] ?? null;
    unset( $wp_filter['woocommerce_new_order'] );
    $new = new WC_Order();
    $new->set_billing_email( $email );
    $orders[] = $new->save();
    if ( $hooks ) {
        $wp_filter['woocommerce_new_order'] = $hooks;
    }
    return end( $orders );
};
$oe          = "ord@{$domain}";
$oid         = $saved_order( $oe );
$about       = fn( string $subject, array $run = [], array $steps = [] ) => $new_run(
    $run + [
        'subject' => $subject,
        'scope'   => $json( [ 'trigger' => [ 'ref' => $subject ] ] ),
    ],
    $steps
);
$wait_on     = [
    'status'      => 'waiting',
    'finished_at' => null,
    'wait_until'  => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
];
$o_wait      = $about( "order:{$oid}", $wait_on, [ [ 'status' => 'waiting' ] ] );
$o_done      = $about( "order:{$oid}" );
$export      = $privacy->export( $oe, 1 );
$ok( $export['done'] && array_column( $export['data'], 'item_id' ) === [ "yaymatik-run-{$o_wait}", "yaymatik-run-{$o_done}" ], 'export: runs about an order with that billing email, waiting and finished, listed' );
$o_before = $run_row( $o_done );
$erased   = $privacy->erase( $oe, 1 );
$o_after  = $run_row( $o_wait );
$ok(
    $erased['done'] && $erased['items_removed'] && [] === $erased['messages'] && 'cancelled' === $o_after['status'] && $reason === $o_after['error'] && 'cancelled' === $step_row( $o_wait )['status'],
    'erase: a waiting run about the order cancelled with the erase reason, done'
);
$ok( $o_before === $run_row( $o_done ), 'erase: a finished run about the order unchanged, not a no-change page' );
$erased = $privacy->erase( $oe, 1 );
$ok( $erased['done'] && ! $erased['items_removed'] && [] === $erased['messages'], 'erase again: nothing found, done' );

$ce     = "cartrun@{$domain}";
$cart_c = $saved( $prefix . 'cartrun', [ $line( $p1, 1 ) ] );
$wpdb->update( $table, [ 'email' => $ce ], [ 'id' => (int) $cart_c['id'] ] );
$c_run  = $about(
    'cart:' . $cart_c['id'],
    [
        'status'      => 'running',
        'finished_at' => null,
    ]
);
$erased = $privacy->erase( $ce, 1 );
$ok( $erased['done'] && [ 'A workflow run is in progress; erase again in a few minutes.' ] === $erased['messages'] && null !== $row( $prefix . 'cartrun' ), 'erase: a run about the cart in progress → retained, the cart kept for the next erase' );
$wpdb->update( $runs_table, $wait_on, [ 'id' => $c_run ] );
$erased = $privacy->erase( $ce, 1 );
$ok( $erased['done'] && $erased['items_removed'] && 'cancelled' === $run_row( $c_run )['status'] && null === $row( $prefix . 'cartrun' ), 'erase again once it waits: the run about the cart cancelled, then the cart deleted' );

// 120 runs: three pages each way.
for ( $i = 0; $i < 120; $i++ ) {
    $new_run( [ 'scope' => $json( [ 'email' => "pg@{$domain}" ] ) ] );
}
$pages = [];
for ( $page = 1; $page <= 3; $page++ ) {
    $result  = $privacy->export( "pg@{$domain}", $page );
    $pages[] = [ count( $result['data'] ), $result['done'] ];
}
$ok( [ [ 50, false ], [ 50, false ], [ 20, true ] ] === $pages, 'export, 120 runs: pages of 50, 50, 20; done on the third' );
$pages = [];
for ( $page = 1; $page <= 3; $page++ ) {
    $pages[] = $privacy->erase( "pg@{$domain}", $page )['done'];
}
$ok( [ false, false, true ] === $pages && [] === RunStore::ids_mentioning( "pg@{$domain}", [], 50 ), 'erase, 120 runs: done on the third page, none left' );

// A page that finds runs but changes none ends instead of looping.
$new_run( [ 'scope' => $json( [ 'email' => "nc@{$domain}" ] ) ] );
$drop   = fn( $query ) => preg_match( '/^UPDATE `' . preg_quote( $runs_table, '/' ) . '/', $query ) ? 'SELECT 1 FROM DUAL WHERE 0' : $query;
$logged = 0;
$count  = function ( $message ) use ( &$logged ) {
    $logged += 'Erase found runs but changed none; stopped.' === $message ? 1 : 0;
    return $message;
};
add_filter( 'query', $drop );
add_filter( 'woocommerce_logger_log_message', $count );
$erased = $privacy->erase( "nc@{$domain}", 1 );
remove_filter( 'query', $drop );
remove_filter( 'woocommerce_logger_log_message', $count );
// The log filter runs once per log handler.
$ok( $erased['done'] && ! $erased['items_removed'] && $erased['items_retained'] && 1 === count( $erased['messages'] ) && $logged > 0, 'erase: a page that changes nothing → done, retained with a message, logged' );
$ok(
    [
        'items_removed'  => false,
        'items_retained' => false,
        'messages'       => [],
        'done'           => true,
    ] === $privacy->erase( '', 1 ),
    'erase: not an email → nothing, done'
);
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
