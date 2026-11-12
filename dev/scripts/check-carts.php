<?php
/**
 * Cart tracking on the real carts table, through CartTracker's own methods. Each "browser" is a WooCommerce
 * session handler that is never started (no cookie, nothing saved), keyed `t_check…` or a temporary user's id.
 * Orders are WC_Order objects with a fake id that are never saved, so no order hook or workflow runs.
 * Email capture uses a real WC_Cart holding one temporary hidden product; the classic endpoint's answer is
 * read through the AJAX die handler. Restore calls RestoreCart::restore() (the request handler around it redirects
 * and exits) with more temporary products. Cleanup and the privacy exporter / eraser run on temporary runs and
 * emails at a random `.test` domain; cleanup also deletes the site's own runs and carts past the longest retention.
 * The sweep also marks the site's own due carts.
 * Prints PASS / FAIL lines; removes its rows and users. Run through check-carts.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

use YayMatik\Engine\FEPages\RestoreCart;
use YayMatik\Engine\FEPages\UnsubscribePage;
use YayMatik\Engine\Privacy\PersonalData;
use YayMatik\Workflow\Core\Cart\CartSnapshot;
use YayMatik\Workflow\Core\Cart\CartSweep;
use YayMatik\Workflow\Core\Cart\CartTracker;
use YayMatik\Workflow\Core\Cart\EmailCapture;
use YayMatik\Workflow\Core\Cart\RestoreLink;
use YayMatik\Workflow\Core\Email\CartItems;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Core\Runtime\Cancellation;
use YayMatik\Workflow\Core\Runtime\Cleanup;
use YayMatik\Workflow\Core\Runtime\RunPresenter;
use YayMatik\Workflow\Core\Runtime\Runtime;
use YayMatik\Workflow\Core\Storage\CartStore;
use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\Schema;
use YayMatik\Workflow\Core\Storage\Settings;
use YayMatik\Workflow\Core\Storage\UnsubscribeStore;
use YayMatik\Workflow\Core\Storage\WorkflowStore;

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
$flows  = [];
$domain = 'x' . wp_rand( 1000, 9999 ) . '.test';

$fails = 0;
$ok    = function ( bool $pass, string $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $fails += $pass ? 0 : 1;
};

$cleanup = function () use ( $wpdb, $table, $prefix, $domain, &$users, &$posts, &$runs, &$orders, &$flows ) {
    // By workflow and by cart, not only `$runs`: a sweep also starts runs on carts the script never asks about. Before the carts go.
    $runs_table = RunStore::table();
    $flow_list  = $flows ? implode( ',', array_map( 'intval', $flows ) ) : '0';
    $user_list  = "'" . implode( "','", array_map( 'intval', $users ) ) . "'";
    $found      = $wpdb->get_col( $wpdb->prepare( "SELECT r.id FROM {$runs_table} r LEFT JOIN {$table} c ON r.subject = CONCAT('cart:', c.id) WHERE r.workflow_id IN ({$flow_list}) OR c.session_key LIKE %s OR c.session_key IN ({$user_list})", $wpdb->esc_like( $prefix ) . '%' ) );
    $runs       = array_values( array_unique( array_merge( array_map( 'intval', $runs ), array_map( 'intval', $found ) ) ) );
    foreach ( $runs as $run_id ) {
        foreach ( [ ActionSchedulerQueue::HOOK, ActionSchedulerQueue::RECOVER_HOOK ] as $hook ) {
            $jobs = as_get_scheduled_actions(
                [
                    'hook'     => $hook,
                    'args'     => [ $run_id ],
                    'group'    => ActionSchedulerQueue::GROUP,
                    'status'   => '',
                    'per_page' => -1,
                ],
                'ids'
            );
            foreach ( $jobs as $job_id ) {
                ActionScheduler::store()->delete_action( $job_id );
            }
        }
    }
    $claims = Schema::table( Schema::TRIGGER_CLAIMS );
    $wpdb->query( "DELETE FROM {$claims} WHERE workflow_id IN ({$flow_list})" );
    foreach ( $flows as $flow_id ) {
        WorkflowStore::deactivate( $flow_id );
        WorkflowStore::delete( $flow_id );
    }
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
$first = apply_filters( 'render_block_woocommerce/checkout', $block, [] ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- WordPress's own block hook.
$ok( 1 === substr_count( $first, $added_text ) && 0 === strpos( $first, '<div class="woocommerce-notices-wrapper">' ) && null === WC()->session->get( 'yaymatik_restore_notice' ) && [] === (array) wc_get_notices(), 'Checkout block: the notice printed once before the block, flag and notices cleared' );
$ok( apply_filters( 'render_block_woocommerce/checkout', $block, [] ) === $block, 'Checkout block, next render: nothing added' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- WordPress's own block hook.

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
    // Written past the stores, so indexed here as they would.
    RunStore::index_emails( [ $run['scope'] ?? '{}' ], array_filter( [ $run['error'] ?? null ] ), 'SELECT %d AS run_id', [ $id ] );
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
        RunStore::index_emails( array_filter( [ $step['input'] ?? null, $step['output'] ?? null ] ), array_filter( [ $step['error'] ?? null ] ), 'SELECT %d AS run_id', [ $id ] );
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
    if ( 0 === strpos( $sql, "DELETE FROM `{$table}` " ) ) {
        $queries[] = 'carts';
    } elseif ( false !== strpos( $sql, "FROM `{$runs_table}` r WHERE" ) ) {
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
as_unschedule_all_actions( CartSweep::HOOK );
delete_option( Cleanup::CHECKED_OPTION );
Cleanup::ensure();
Cleanup::ensure();
$jobs = as_get_scheduled_actions(
    [
        'hook'     => CartSweep::HOOK,
        'status'   => 'pending',
        'per_page' => -1,
    ]
);
$job  = reset( $jobs );
$ok( 1 === count( $jobs ) && 'yaymatik' === $job->get_group() && 300 === (int) $job->get_schedule()->get_recurrence(), 'ensure: one sweep job every 5 minutes in the yaymatik group' );

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

// Abandon sweep. Each cart is an hour past the 60 minutes unless a check says otherwise.
$settings( [ 'abandon_minutes' => 60 ] + $on );
$idle   = 'last_activity_at = UTC_TIMESTAMP() - INTERVAL 2 HOUR';
$sw     = $prefix . 'sw_';
$fired  = [];
$listen = function ( $id, $kind ) use ( &$fired ) {
    $fired[ (int) $id ] = $kind;
};
add_action( 'yaymatik_cart_abandoned', $listen, 10, 2 );
$due_row = function ( string $name, string $columns = '' ) use ( $saved, $age, $sw, $line, $idle ) {
    $saved( $sw . $name, [ $line( 1, 1 ) ] );
    $age( $sw . $name, $idle . ( '' === $columns ? '' : ", {$columns}" ) );
};

$due_row( 'due' );
$due_row( 'checkout', 'checkout_at = UTC_TIMESTAMP()' );
$due_row( 'noemail', "email = ''" );
$due_row( 'noitems', "items = '[]'" );
$due_row( 'blank', "items = ''" );
$due_row( 'new', 'last_activity_at = UTC_TIMESTAMP() - INTERVAL 30 MINUTE' );
$due_row( 'stale', 'last_activity_at = UTC_TIMESTAMP() - INTERVAL 26 HOUR' );
$due_row( 'ordered', "status = 'ordered'" );
$settings( [ 'cart_tracking' => false ] + $on );
CartSweep::run();
$ok( 'active' === $row( $sw . 'due' )['status'] && [] === $fired, 'sweep, tracking off: nothing marked' );
$settings( [ 'abandon_minutes' => 60 ] + $on );
$updated = $row( $sw . 'due' )['updated_at'];
CartSweep::run();
$r = $row( $sw . 'due' );
$ok( 'abandoned' === $r['status'] && 'cart_abandoned' === ( $fired[ (int) $r['id'] ] ?? '' ), 'sweep: a due cart abandoned, fired as cart_abandoned' );
$ok( null === $r['abandoned_at'] && '' === $r['abandon_trigger'] && null === $r['series_at'], 'sweep: no workflow took it, so nothing locked' );
$ok( $updated === $r['updated_at'], 'sweep: updated_at untouched, the capture limit counts only the shopper' );
$r2 = $row( $sw . 'checkout' );
$ok( 'abandoned' === $r2['status'] && 'checkout_abandoned' === ( $fired[ (int) $r2['id'] ] ?? '' ), 'sweep: a cart that reached checkout fires as checkout_abandoned' );
foreach ( [ 'noemail', 'noitems', 'blank', 'new', 'stale' ] as $name ) {
    $ok( 'active' === $row( $sw . $name )['status'] && ! isset( $fired[ (int) $row( $sw . $name )['id'] ] ), "sweep ignores {$name}" );
}
$ok( 'ordered' === $row( $sw . 'ordered' )['status'] && ! isset( $fired[ (int) $row( $sw . 'ordered' )['id'] ] ), 'sweep ignores an ordered cart' );

// Nothing took the first abandon: the shopper returns, reaches checkout and leaves again.
$browser( $sw . 'due' );
CartTracker::record( $cart( [ $line( 1, 2 ) ] ), 'cart' );
$ok( 'active' === $row( $sw . 'due' )['status'], 'record on an abandoned cart: active' );
$age( $sw . 'due', $idle . ', checkout_at = UTC_TIMESTAMP()' );
$fired = [];
CartSweep::run();
$r = $row( $sw . 'due' );
$ok( 'abandoned' === $r['status'] && 'checkout_abandoned' === ( $fired[ (int) $r['id'] ] ?? '' ), 'second abandon, no series yet: fires again, now as checkout_abandoned' );

// A series started: activity keeps it, and the next abandon fires nothing.
$first_at = '2026-01-02 03:04:05';
$age( $sw . 'due', "abandoned_at = '{$first_at}', abandon_trigger = 'cart_abandoned', series_at = '{$first_at}'" );
$browser( $sw . 'due' );
CartTracker::record( $cart( [ $line( 1, 2 ) ] ), 'cart' );
$r = $row( $sw . 'due' );
$ok( 'active' === $r['status'] && $first_at === $r['abandoned_at'] && 'cart_abandoned' === $r['abandon_trigger'], 'record on a cart in a series: active, abandoned_at and trigger kept' );
$age( $sw . 'due', $idle );
$fired = [];
CartSweep::run();
$r = $row( $sw . 'due' );
$ok( 'abandoned' === $r['status'] && $first_at === $r['abandoned_at'] && 'cart_abandoned' === $r['abandon_trigger'] && [] === $fired, 'abandon after a series started: abandoned, series kept, no action' );

add_filter( 'woocommerce_is_checkout', '__return_true' );
$browser( $sw . 'due' );
CartTracker::view();
remove_filter( 'woocommerce_is_checkout', '__return_true' );
$r = $row( $sw . 'due' );
$ok( 'active' === $r['status'] && $first_at === $r['abandoned_at'] && gmdate( 'Y-m-d' ) === substr( $r['last_activity_at'], 0, 10 ) && strtotime( $r['last_activity_at'] . ' UTC' ) > time() - 60, 'view of an abandoned cart: active, last activity now, abandoned_at kept' );

$age( $sw . 'due', "status = 'abandoned'" );
CartStore::link_order( $sw . 'due', $fake + 30, 'x@example.test', 'X' );
$r = $row( $sw . 'due' );
$ok( 'active' === $r['status'] && $first_at === $r['abandoned_at'] && $fake + 30 === (int) $r['order_id'], 'order linked to an abandoned cart: active, abandoned_at kept' );
$age( $sw . 'ordered', "order_id = {$fake}" );
CartStore::link_order( $sw . 'ordered', $fake + 31, 'x@example.test', 'X' );
$ok( 'ordered' === $row( $sw . 'ordered' )['status'], 'order linked to an ordered cart: still ordered' );

// Activity came in after the sweep read the row.
$due_row( 'race' );
$read = $row( $sw . 'race' );
$age( $sw . 'race', 'last_activity_at = UTC_TIMESTAMP()' );
$ok( false === CartStore::mark_abandoned( (int) $read['id'], $read['last_activity_at'] ) && 'active' === $row( $sw . 'race' )['status'] && null === $row( $sw . 'race' )['abandoned_at'], 'activity after the read: not marked' );

// New cycle.
$age( $sw . 'race', "status = 'ordered', abandoned_at = UTC_TIMESTAMP(), abandon_trigger = 'cart_abandoned', series_at = UTC_TIMESTAMP()" );
CartStore::reset_cycle( (int) $read['id'] );
$r = $row( $sw . 'race' );
$ok( 'active' === $r['status'] && null === $r['abandoned_at'] && '' === $r['abandon_trigger'] && null === $r['series_at'], 'reset_cycle clears abandoned_at, abandon_trigger and series_at' );

// Paid by email closes an abandoned cart.
$by_email = "abandoned@{$domain}";
$due_row( 'paid', "email = '{$by_email}', status = 'abandoned'" );
CartTracker::order_status_changed( $fake + 32, 'pending', 'processing', $order( $fake + 32, $by_email, 25.0 ) );
$r = $row( $sw . 'paid' );
$ok( 'ordered' === $r['status'] && $fake + 32 === (int) $r['order_id'], 'paid by email: an abandoned cart ordered, order_id set' );
remove_action( 'yaymatik_cart_abandoned', $listen );

// Cart triggers: real workflows through the real Dispatcher; a sweep's events start at flush().
$cart_runs = function ( int $cart_id ) use ( $wpdb, $runs_table, &$runs ) {
    $ids  = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$runs_table} WHERE subject = %s ORDER BY id", 'cart:' . $cart_id ) ) );
    $runs = array_values( array_unique( array_merge( $runs, $ids ) ) );
    return $ids;
};
// Runs a workflow started, and the reasons of the skipped ones a refused claim recorded.
$started   = fn( int $cart_id ) => array_values( array_filter( $cart_runs( $cart_id ), fn( $id ) => 'skipped' !== RunStore::find( $id )['status'] ) );
$skips     = fn( int $cart_id ) => array_values( array_map( fn( $id ) => RunStore::find( $id )['error'], array_filter( $cart_runs( $cart_id ), fn( $id ) => 'skipped' === RunStore::find( $id )['status'] ) ) );
$week_text = 'This shopper already got a reminder series in the last 7 days.';
$sweep     = function () {
    CartSweep::run();
    Runtime::dispatcher()->flush();
};
$series    = "series@{$domain}";
Runtime::dispatcher()->flush();

$due_row( 'noflow', "email = '{$series}'" );
$sweep();
$r = $row( $sw . 'noflow' );
$ok( 'abandoned' === $r['status'] && [] === $cart_runs( (int) $r['id'] ) && null === $r['series_at'], 'no active workflow: abandoned, no run, no series' );

$admin    = get_users(
    [
        'role'   => 'administrator',
        'number' => 1,
        'fields' => 'ID',
    ]
);
$new_flow = function ( string $name, string $trigger, bool $two_emails = false, string $message = '<p>{{ cart.total }}</p>' ) use ( $admin, &$flows ) {
    wp_set_current_user( (int) $admin[0] );
    $graph = [
        'nodes' => [
            [
                'id'       => 'trigger',
                'kind'     => 'trigger',
                'position' => [
                    'x' => 0,
                    'y' => 0,
                ],
                'data'     => [
                    'block'  => $trigger,
                    'config' => (object) [],
                ],
            ],
            [
                'id'       => 'mail',
                'kind'     => 'action',
                'position' => [
                    'x' => 0,
                    'y' => 170,
                ],
                'data'     => [
                    'block'  => 'send_email',
                    'config' => (object) [
                        'to'      => '{{ customer.email }}',
                        'subject' => 'Check carts: {{ cart.product_names }}',
                        'heading' => 'Hi',
                        'message' => $message,
                    ],
                ],
            ],
        ],
        'edges' => [
            [
                'id'     => 'e1',
                'source' => 'trigger',
                'target' => 'mail',
            ],
        ],
    ];
    if ( $two_emails ) {
        $second             = $graph['nodes'][1];
        $second['id']       = 'mail2';
        $second['position'] = [
            'x' => 0,
            'y' => 340,
        ];
        $graph['nodes'][]   = $second;
        $graph['edges'][]   = [
            'id'     => 'e2',
            'source' => 'mail',
            'target' => 'mail2',
        ];
    }
    $request = new WP_REST_Request( 'POST', '/yaymatik/v1/workflows' );
    $request->set_header( 'content-type', 'application/json' );
    $request->set_body(
        wp_json_encode(
            [
                'name'  => "Check carts: {$name} (temporary)",
                'graph' => $graph,
            ]
        )
    );
    $id      = (int) rest_do_request( $request )->get_data()['id'];
    $flows[] = $id;
    $status  = rest_do_request( new WP_REST_Request( 'POST', "/yaymatik/v1/workflows/{$id}/activate" ) )->get_status();
    return 200 === $status ? $id : 0;
};
// Only Checkout on: an abandon before checkout uses nothing up; the same cart starts Checkout later.
$checkout_flow = $new_flow( 'checkout', 'checkout_abandoned' );
$due_row( 'later' );
$sweep();
$r = $row( $sw . 'later' );
$ok( 'abandoned' === $r['status'] && [] === $cart_runs( (int) $r['id'] ) && null === $r['series_at'], 'only Checkout on, cart abandoned before checkout: no run, no series' );
$age( $sw . 'later', $idle . ", status = 'active', checkout_at = UTC_TIMESTAMP()" );
$sweep();
$r     = $row( $sw . 'later' );
$found = $cart_runs( (int) $r['id'] );
$ok( 1 === count( $found ) && (int) RunStore::find( $found[0] )['workflow_id'] === $checkout_flow && 'checkout_abandoned' === $r['abandon_trigger'] && null !== $r['series_at'], 'only Checkout on, same cart left at checkout later: one Checkout run, series locked as checkout' );

$cart_flow = $new_flow( 'cart', 'cart_abandoned' );
$ok( $cart_flow > 0 && $checkout_flow > 0, 'a workflow per cart trigger activates' );

$due_row( 'tcart' );
$due_row( 'tcheckout', 'checkout_at = UTC_TIMESTAMP()' );
$sweep();
$r     = $row( $sw . 'tcart' );
$found = $cart_runs( (int) $r['id'] );
$run   = $found ? RunStore::find( $found[0] ) : null;
$ok( 1 === count( $found ) && (int) $run['workflow_id'] === $cart_flow && 'cart_abandoned' === $run['trigger_id'] && null !== $r['series_at'], 'cart kind: one run, of the cart workflow only; series_at set' );
$keys = array_map( fn( $value ) => Ref::from_json( $value )->key(), (array) ( $run['scope']['trigger'] ?? [] ) );
$ok( 'cart:' . $r['id'] === $run['subject'] && 'cart:' . $r['id'] === $keys['cart'] && 'customer:cart:' . $r['id'] === $keys['customer'] && 2 === count( $keys ), 'cart kind: subject and refs on the cart' );
$found = $cart_runs( (int) $row( $sw . 'tcheckout' )['id'] );
$ok( 1 === count( $found ) && (int) RunStore::find( $found[0] )['workflow_id'] === $checkout_flow, 'checkout kind: one run, of the checkout workflow only' );

// Both on: a cart in a Cart series, left again at checkout, gets no second series.
$age( $sw . 'tcart', $idle . ", status = 'active', checkout_at = UTC_TIMESTAMP()" );
$sweep();
$r = $row( $sw . 'tcart' );
$ok( 1 === count( $cart_runs( (int) $r['id'] ) ) && 'cart_abandoned' === $r['abandon_trigger'], 'both on, a cart in a series left again at checkout: still one run, kind kept' );

// Series per email.
$unsub = "unsub@{$domain}";
UnsubscribeStore::add( $unsub );
$due_row( 'unsub', "email = '{$unsub}'" );
$sweep();
$r = $row( $sw . 'unsub' );
$ok( 'abandoned' === $r['status'] && [] === $started( (int) $r['id'] ) && [ Cancellation::unsubscribed() ] === $skips( (int) $r['id'] ) && null === $r['series_at'], 'unsubscribed: abandoned, no run started, one Skipped run with the reason, no series' );

$pair = "pair@{$domain}";
$due_row( 'younger', "email = '{$pair}'" );
$due_row( 'older', "email = '{$pair}', last_activity_at = UTC_TIMESTAMP() - INTERVAL 3 HOUR" );
$sweep();
$ok( 1 === count( $started( (int) $row( $sw . 'older' )['id'] ) ) && [] === $started( (int) $row( $sw . 'younger' )['id'] ) && [ $week_text ] === $skips( (int) $row( $sw . 'younger' )['id'] ) && null === $row( $sw . 'younger' )['series_at'], 'two carts, one email, one sweep: only the older starts, though its id is higher; the younger is Skipped' );

$due_row( 'unblocked', "email = '{$series}'" );
$sweep();
$ok( 1 === count( $cart_runs( (int) $row( $sw . 'unblocked' )['id'] ) ), 'an earlier cart abandoned with no workflow does not block' );

$past = "past@{$domain}";
$due_row( 'ordered2', "email = '{$past}', status = 'ordered', series_at = UTC_TIMESTAMP()" );
$due_row( 'afterorder', "email = '{$past}'" );
$sweep();
$ok( 1 === count( $cart_runs( (int) $row( $sw . 'afterorder' )['id'] ) ), 'an ordered cart\'s series does not block' );

$week = "week@{$domain}";
$due_row( 'eight', "email = '{$week}', status = 'abandoned', abandoned_at = UTC_TIMESTAMP(), series_at = UTC_TIMESTAMP() - INTERVAL 8 DAY" );
$due_row( 'aftereight', "email = '{$week}'" );
$sweep();
$ok( 1 === count( $cart_runs( (int) $row( $sw . 'aftereight' )['id'] ) ), 'a series 8 days old does not block' );
$six = "six@{$domain}";
$due_row( 'six', "email = '{$six}', status = 'abandoned', abandoned_at = UTC_TIMESTAMP(), series_at = UTC_TIMESTAMP() - INTERVAL 6 DAY" );
$due_row( 'aftersix', "email = '{$six}'" );
$sweep();
$r = $row( $sw . 'aftersix' );
$ok( [] === $started( (int) $r['id'] ) && [ $week_text ] === $skips( (int) $r['id'] ), 'a series 6 days old blocks: a Skipped run with the reason' );
$skip  = $cart_runs( (int) $r['id'] )[0];
$stats = RunStore::stats( [ $cart_flow ] )[ $cart_flow ] ?? [];
$ok(
    in_array(
        $skip,
        array_column(
            RunStore::list(
                [
                    'workflow_id' => $cart_flow,
                    'status'      => 'skipped',
                ]
            ),
            'id'
        ),
        true
    ) && ( $stats['last_id'] ?? 0 ) === $skip && 'skipped' === ( $stats['last_status'] ?? '' ) && ( $stats['recent'] ?? 0 ) >= 1,
    'the Skipped run is listed under Skipped and counts as the workflow\'s last run'
);

$due_row( 'late', "email = 'late@{$domain}'" );
CartSweep::run();
$age( $sw . 'late', "status = 'ordered'" );
Runtime::dispatcher()->flush();
$r = $row( $sw . 'late' );
$ok( [] === $started( (int) $r['id'] ) && [ Cancellation::order_placed( 0 ) ] === $skips( (int) $r['id'] ) && null === $r['series_at'], 'ordered between the sweep and its shutdown: no run started, Skipped as ordered, no series' );

// Account with no billing email: the run still reaches the cart's address.
$sent = [];
$mail = function ( $short_circuit, $atts ) use ( &$sent ) {
    $sent[] = $atts;
    return true;
};
add_filter( 'pre_wp_mail', $mail, 10, 2 );
$u = $user( 'series', 'Una' );
$saved( (string) $u, [ $line( 1, 1 ) ], $u );
$age( (string) $u, $idle );
$sweep();
$r     = $row( (string) $u );
$found = $cart_runs( (int) $r['id'] );
as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $found[0] ?? 0 ], ActionSchedulerQueue::GROUP );
Runtime::worker()->run( $found[0] ?? 0 );
$to = (array) ( $sent[0]['to'] ?? [] );
$ok( '' === get_user_meta( $u, 'billing_email', true ) && 'completed' === RunStore::find( $found[0] ?? 0 )['status'] && [ $r['email'] ] === $to, 'logged-in customer, no billing email: the email goes to the cart\'s address' );
$ok( false !== strpos( (string) ( $sent[0]['subject'] ?? '' ), 'Check product 1' ), 'cart data reaches the email' );
remove_filter( 'pre_wp_mail', $mail );

// Stop on order. A run parked on its Wait, as after the first slice of a real series.
$waiting    = function ( string $key, string $columns = '' ) use ( $due_row, $sweep, $sw, $row, $cart_runs, $wpdb, $runs_table, $steps_table ) {
    $due_row( $key, $columns );
    $sweep();
    $cart_id = (int) $row( $sw . $key )['id'];
    $run_id  = $cart_runs( $cart_id )[0];
    $wpdb->update(
        $runs_table,
        [
            'status'     => 'waiting',
            'wait_until' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
        ],
        [ 'id' => $run_id ]
    );
    $wpdb->insert(
        $steps_table,
        [
            'run_id'  => $run_id,
            'step_id' => 'wait',
            'status'  => 'waiting',
        ]
    );
    return $run_id;
};
$stop_order = wc_create_order();
$stop_order->set_billing_email( "stop@{$domain}" );
$stop_order->set_total( 25 );
$stop_order->save();
$orders[]  = $stop_order->get_id();
$placed    = 'The shopper placed order #' . $stop_order->get_order_number() . '.';
$other_run = $waiting( 'bystander' );

$by_order = $waiting( 'byorder', 'order_id = ' . $stop_order->get_id() );
CartTracker::order_status_changed( $stop_order->get_id(), 'pending', 'processing', $order( $stop_order->get_id(), "byorder@{$domain}", 0.0 ) );
$ok( 'cancelled' === $run_row( $by_order )['status'] && $placed === $run_row( $by_order )['error'] && 'cancelled' === $step_row( $by_order )['status'], 'paid by order: the waiting run cancelled at once with the order number' );
$ok( 'waiting' === $run_row( $other_run )['status'], 'another cart\'s run untouched' );

$stop_email = "stop@{$domain}";
$by_email   = $waiting( 'byemail', "email = '{$stop_email}'" );
$ok( 'abandoned' === $row( $sw . 'byemail' )['status'], 'paid by email: the cart starts abandoned' );
CartTracker::order_status_changed( $stop_order->get_id(), 'pending', 'processing', $order( $stop_order->get_id(), $stop_email, 25.0 ) );
$r = $row( $sw . 'byemail' );
$ok( 'ordered' === $r['status'] && $stop_order->get_id() === (int) $r['order_id'] && 'cancelled' === $run_row( $by_email )['status'] && $placed === $run_row( $by_email )['error'], 'paid by email: an abandoned cart ordered with order_id; its run cancelled with the order number' );

$free_email = "free@{$domain}";
$free_run   = $waiting( 'free', "email = '{$free_email}'" );
CartTracker::order_status_changed( $fake + 40, 'pending', 'processing', $order( $fake + 40, $free_email, 0.0 ) );
$ok( 'waiting' === $run_row( $free_run )['status'] && 'abandoned' === $row( $sw . 'free' )['status'], 'a free order closes only its own cart: another cart with the email goes on' );

$gone_run = $waiting( 'emptied' );
$browser( $sw . 'emptied' );
$next_request();
CartTracker::emptied();
$ok( null === $row( $sw . 'emptied' ) && 'cancelled' === $run_row( $gone_run )['status'] && 'The cart was emptied or deleted.' === $run_row( $gone_run )['error'] && 'cancelled' === $step_row( $gone_run )['status'], 'emptied: row gone, run cancelled "emptied or deleted"' );

$unpaid_run = $waiting( 'unpaid', 'order_id = ' . ( $fake + 41 ) );
$browser( $sw . 'unpaid' );
$next_request();
CartTracker::emptied();
$ok( null !== $row( $sw . 'unpaid' ) && 'cancelled' === $run_row( $unpaid_run )['status'] && '[]' === $row( $sw . 'unpaid' )['items'], 'emptied with an unpaid order: row kept for the payment, no items, run cancelled' );
$age( $sw . 'unpaid', "status = 'active', {$idle}, series_at = NULL, abandoned_at = NULL, abandon_trigger = ''" );
$fired = [];
add_action( 'yaymatik_cart_abandoned', $listen, 10, 2 );
$sweep();
remove_action( 'yaymatik_cart_abandoned', $listen );
$ok( 'active' === $row( $sw . 'unpaid' )['status'] && ! isset( $fired[ (int) $row( $sw . 'unpaid' )['id'] ] ), 'emptied with an unpaid order: the sweep skips it' );
$ok( 'The cart was emptied or deleted.' === YayMatik\Workflow\Core\Steps\StepRegistry::get( 'cart_abandoned' )->stop_reason( 'cart:' . $row( $sw . 'unpaid' )['id'] ), 'emptied with an unpaid order: a queued run stops too' );

$settings(
    [
        'cart_retention_days' => 365,
        'run_retention_days'  => 730,
    ] + $on
);
$old_run = $waiting( 'expired' );
$age( $sw . 'expired', "last_activity_at = '{$old}'" );
Cleanup::run();
$ok( null === $row( $sw . 'expired' ) && 'cancelled' === $run_row( $old_run )['status'] && 'The cart was emptied or deleted.' === $run_row( $old_run )['error'], 'cleanup: old cart gone, its waiting run cancelled' );
$settings( [ 'abandon_minutes' => 60 ] + $on );

$back_run = $waiting( 'back' );
$browser( $sw . 'back' );
$next_request();
CartTracker::record( $cart( [ $line( 1, 4 ) ] ), 'cart' );
$ok( 'active' === $row( $sw . 'back' )['status'] && 'waiting' === $run_row( $back_run )['status'], 'a returning shopper: cart active, run still waiting' );

// Unsubscribed: stops the run before a coupon or an email nobody gets.
$left       = 'The shopper unsubscribed from these emails.';
$left_mail  = "left@{$domain}";
$left_run   = $waiting( 'left', "email = '{$left_mail}'" );
$left_order = $new_run(
    [
        'subject'     => 'order:' . ( $fake + 42 ),
        'status'      => 'waiting',
        'finished_at' => null,
        'wait_until'  => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
    ],
    [ [ 'status' => 'waiting' ] ]
);
$ok( UnsubscribePage::unsubscribe( strtoupper( $left_mail ) ) && UnsubscribeStore::has( $left_mail ), 'unsubscribe: recorded, any case' );
$ok( 'cancelled' === $run_row( $left_run )['status'] && $left === $run_row( $left_run )['error'] && 'cancelled' === $step_row( $left_run )['status'], 'unsubscribe: the waiting cart run cancelled at once' );
$ok( 'waiting' === $run_row( $left_order )['status'] && 'waiting' === $step_row( $left_order )['status'], 'unsubscribe: a waiting order run untouched' );
$ok( null === YayMatik\Workflow\Core\Steps\StepRegistry::get( 'order_created' )->stop_reason( 'order:' . ( $fake + 42 ) ), 'unsubscribe: an order trigger has no stop reason' );
$ok( 'waiting' === $run_row( $back_run )['status'], 'unsubscribe: another email\'s cart run untouched' );
$ok( UnsubscribePage::unsubscribe( "nocart@{$domain}" ), 'unsubscribe: an email with no carts' );
$ok( ! UnsubscribePage::unsubscribe( 'not an email' ), 'unsubscribe: not an email is refused' );

// Queued, then running: stopped by the trigger hook.
$due_row( 'queued' );
$sweep();
$queued_cart = $row( $sw . 'queued' );
$queued_run  = $cart_runs( (int) $queued_cart['id'] )[0];
$age( $sw . 'queued', "status = 'ordered', order_id = " . $stop_order->get_id() );
as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $queued_run ], ActionSchedulerQueue::GROUP );
$ok( 'cancelled' === Runtime::worker()->run( $queued_run ) && $placed === $run_row( $queued_run )['error'], 'queued run + ordered cart: Cancelled at start, with the order number' );

$due_row( 'gonequeued' );
$sweep();
$gone_cart = (int) $row( $sw . 'gonequeued' )['id'];
$gone_q    = $cart_runs( $gone_cart )[0];
$wpdb->delete( $table, [ 'id' => $gone_cart ] );
as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $gone_q ], ActionSchedulerQueue::GROUP );
$ok( 'cancelled' === Runtime::worker()->run( $gone_q ) && 'The cart was emptied or deleted.' === $run_row( $gone_q )['error'], 'queued run + gone cart: Cancelled, not skipped' );

$late_mail = "late@{$domain}";
$due_row( 'late', "email = '{$late_mail}'" );
$sweep();
$late_run = $started( (int) $row( $sw . 'late' )['id'] )[0];
UnsubscribeStore::add( $late_mail );
as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $late_run ], ActionSchedulerQueue::GROUP );
$ok( 'cancelled' === Runtime::worker()->run( $late_run ) && $left === $run_row( $late_run )['error'], 'queued run + unsubscribed email: Cancelled at start' );

$two_flow = $new_flow( 'two emails', 'cart_abandoned', true );
$due_row( 'running' );
$sweep();
$running_id  = (int) $row( $sw . 'running' )['id'];
$running_run = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$runs_table} WHERE subject = %s AND workflow_id = %d", 'cart:' . $running_id, $two_flow ) );
$cart_runs( $running_id );
$sends    = 0;
$ordering = function () use ( &$sends, $age, $sw, $stop_order ) {
    ++$sends;
    $age( $sw . 'running', "status = 'ordered', order_id = " . $stop_order->get_id() );
    return true;
};
add_filter( 'pre_wp_mail', $ordering );
as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $running_run ], ActionSchedulerQueue::GROUP );
$status = Runtime::worker()->run( $running_run );
remove_filter( 'pre_wp_mail', $ordering );
$ok( 'cancelled' === $status && 1 === $sends && $placed === $run_row( $running_run )['error'], 'running run: ordered during its first email, stops before the second' );

// Blocks. A real product whose price changes after the cart was saved, and one that is gone.
$block_product = new WC_Product_Simple();
$block_product->set_name( 'Live & name' );
$block_product->set_status( 'publish' );
$block_product->set_regular_price( '99' );
$block_product->save();
$posts[]   = $block_product->get_id();
$gone_line = [ 'name' => 'Gone product' ] + $line( 999999999, 2, 7.0 );
$snap_line = [ 'name' => 'Snapshot name' ] + $line( $block_product->get_id(), 3, 11.0 );
$blocks    = new YayMatik\Workflow\Core\Cart\SavedCart(
    [
        'id'               => 77,
        'items'            => (string) wp_json_encode( [ $snap_line, $gone_line ] ),
        'total'            => 40.5,
        'currency'         => 'EUR',
        'last_activity_at' => gmdate( 'Y-m-d H:i:s' ),
        'created_at'       => gmdate( 'Y-m-d H:i:s' ),
    ]
);
$html      = CartItems::render( $blocks, true );
$text      = CartItems::render( $blocks, false );
$ok( false !== strpos( $html, '33.00' ) && false === strpos( $html, '99.00' ) && false !== strpos( $html, '40.50' ), 'cart.items: snapshot line total and cart total, not the live price' );
$ok( false !== strpos( $html, 'Live &amp; name' ) && false === strpos( $html, 'Snapshot name' ) && false !== strpos( $html, esc_url( $block_product->get_permalink() ) ), 'cart.items: live name and link' );
$gone_at = strpos( $html, 'Gone product' );
$ok( false !== $gone_at && false === strpos( substr( $html, $gone_at - 60, 60 ), '<a ' ), 'cart.items: a gone product shows its snapshot name without a link' );
$ok( false !== strpos( $html, 'Cart total' ) && false === strpos( $html, "\n" ), 'cart.items: a total row, on one line' );
$ok( false !== strpos( $text, 'Live & name × 3' ) && false !== strpos( $text, 'Gone product × 2' ) && false !== strpos( $text, 'Cart total: ' ) && false === strpos( $text, '<' ), 'cart.items as text: lines, then the total' );
$ok( '' === CartItems::render( new YayMatik\Workflow\Core\Cart\SavedCart( [ 'items' => '[]' ] ), true ), 'cart.items: no lines → empty' );

$parent = new WC_Product_Variable();
$parent->set_name( 'Parent' );
$parent->set_status( 'draft' );
$parent->save();
$posts[]   = $parent->get_id();
$variation = new WC_Product_Variation();
$variation->set_parent_id( $parent->get_id() );
$variation->set_status( 'publish' );
$variation->set_regular_price( '5' );
$variation->save();
$posts[]  = $variation->get_id();
$var_line = [
    'name'         => 'Saved variation',
    'variation_id' => $variation->get_id(),
] + $line( $parent->get_id(), 1, 5.0 );
$var_cart = new YayMatik\Workflow\Core\Cart\SavedCart(
    [
        'items'    => (string) wp_json_encode( [ $var_line ] ),
        'total'    => 5,
        'currency' => 'USD',
    ]
);
$var_html = CartItems::render( $var_cart, true );
$ok( false !== strpos( $var_html, 'Saved variation' ) && false === strpos( $var_html, '<a ' ), 'cart.items: a published variation of a draft parent shows its saved name, no link' );

$block_row = $saved( $sw . 'blocks', [ $line( $block_product->get_id(), 1 ) ] );
$real_cart = YayMatik\Workflow\Core\Cart\SavedCart::find( (int) $block_row['id'] );
parse_str( (string) wp_parse_url( $real_cart->restore_url(), PHP_URL_QUERY ), $args );
$ok( RestoreLink::from_options()->verify( (int) $block_row['id'], $block_row['created_at'], $args[ UnsubscribeLink::ARG_TOKEN ] ), 'restore_url verifies through RestoreLink' );
$button = CartItems::button( $real_cart, true );
$ok( false !== strpos( $button, esc_url( $real_cart->restore_url() ) ) && false !== strpos( $button, 'Restore my cart' ) && false === strpos( $button, "\n" ), 'restore button: real link, on one line' );
$button_color = sanitize_hex_color( (string) get_option( 'woocommerce_email_base_color', '' ) );
$button_color = $button_color ? $button_color : '#7f54b3';
$ok(
    '<table role="presentation" align="center" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto 16px;"><tr><td align="center" bgcolor="' . $button_color . '" style="padding:0;background-color:' . $button_color . ';border-radius:4px;"><a href="' . esc_url( $real_cart->restore_url() ) . '" style="display:inline-block;padding:12px 24px;font-weight:bold;text-decoration:none;color:' . wc_light_or_dark( $button_color, '#202020', '#ffffff' ) . ';">Restore my cart</a></td></tr></table>' === $button,
    'restore button: the bulletproof table, byte for byte'
);
$ok( false !== strpos( CartItems::button( $real_cart->inert(), true ), 'href="#"' ) && '#' === $real_cart->inert()->restore_url(), 'inert cart: the button and link go nowhere' );

// Preview.
$preview_graph = wp_json_encode( WorkflowStore::find( $cart_flow )['graph'] );
$preview       = function ( array $config = [] ) use ( $preview_graph ) {
    $graph = json_decode( $preview_graph, true );
    return YayMatik\Workflow\Core\Email\EmailPreview::render(
        $graph,
        'mail',
        $config + [
            'to'      => '{{ customer.email }}',
            'subject' => 'Your cart',
            'heading' => 'Hi',
            'message' => '<p>{{ cart.items }}</p><p>{{ cart.restore_button }}</p>',
        ]
    );
};
$shown         = $preview();
$ok( ! is_wp_error( $shown ) && false === $shown['sample'] && null === $shown['order'] && false !== strpos( $shown['html'], 'href="#"' ) && false !== strpos( $shown['html'], 'Restore my cart' ) && false === strpos( $shown['html'], UnsubscribeLink::ARG_TOKEN . '=' ), 'preview: a real cart, the button goes nowhere, no token' );
$no_carts = function ( $query ) use ( $table ) {
    return false !== strpos( $query, "SELECT id FROM `{$table}`" ) && false !== strpos( $query, 'ORDER BY id DESC LIMIT 1' ) ? 'SELECT 0' : $query;
};
add_filter( 'query', $no_carts );
$shown = $preview();
remove_filter( 'query', $no_carts );
$ok( ! is_wp_error( $shown ) && true === $shown['sample'] && false !== strpos( $shown['html'], 'Restore my cart' ) && false !== strpos( $shown['html'], 'href="#"' ), 'preview, no carts: sample data, the button goes nowhere' );

// Run list and detail.
$label_row = $row( $sw . 'tcart' );
$a_run     = RunStore::find( $cart_runs( (int) $label_row['id'] )[0] );
$ok( RunPresenter::item( $a_run, 0 )['subjectLabel'] === $label_row['email'], 'run subject: the shopper\'s email' );
$ok( 'Cart #987654' === RunPresenter::item( [ 'subject' => 'cart:987654' ] + $a_run, 0 )['subjectLabel'], 'run subject, gone cart: Cart #id' );
$stopped = RunStore::find( $by_email );
$ok( RunPresenter::detail( $stopped, [], null )['stoppedByOrderUrl'] === $stop_order->get_edit_order_url(), 'run detail: the order link when stopped by an order' );
$ok( null === RunPresenter::detail( $run_row( $gone_run ), [], null )['stoppedByOrderUrl'], 'run detail: no order link when the cart was emptied' );
$late_order = RunStore::find( $by_email );
$ok( null === RunPresenter::detail( [ 'finished_at' => '2001-01-01 00:00:00' ] + $late_order, [], null )['stoppedByOrderUrl'], 'run detail: no order link for an order placed after the run stopped' );
$ok( ! array_key_exists( 'stoppedByOrderUrl', RunPresenter::item( $stopped, 0 ) ), 'runs list: no order link' );

// Activate with tracking off: REST, as the editor calls it.
wp_set_current_user( (int) $admin[0] );
$off_flow = $new_flow( 'tracking off', 'cart_abandoned' );
$settings( [ 'cart_tracking' => false ] + $on );
$activate     = fn( int $id ) => rest_do_request( new WP_REST_Request( 'POST', "/yaymatik/v1/workflows/{$id}/activate" ) );
$update       = function ( int $id, array $graph ) {
    $request = new WP_REST_Request( 'PUT', "/yaymatik/v1/workflows/{$id}" );
    $request->set_header( 'content-type', 'application/json' );
    $request->set_body( wp_json_encode( [ 'graph' => $graph ] ) );
    return rest_do_request( $request );
};
$active_graph = WorkflowStore::find( $off_flow )['graph'];
$ok( 'active' === WorkflowStore::find( $off_flow )['status'] && 200 === $update( $off_flow, $active_graph )->get_status(), 'tracking off: an active workflow stays active and can be updated' );
WorkflowStore::deactivate( $off_flow );
$blocked = $activate( $off_flow );
$errors  = $blocked->get_data()['data']['errors'] ?? [];
$ok( 400 === $blocked->get_status() && 'cart_tracking_off' === ( $errors[0]['code'] ?? '' ) && 'trigger' === ( $errors[0]['nodeId'] ?? '' ) && 'Turn on cart tracking before activating. Cart abandoned never starts while it’s off.' === ( $errors[0]['message'] ?? '' ), 'tracking off: Activate → 400 cart_tracking_off on the trigger, with its message' );
$settings( [ 'abandon_minutes' => 60 ] + $on );
$ok( 200 === $activate( $off_flow )->get_status(), 'tracking on: Activate works' );

// Apply with tracking off: the trigger decides, on an active workflow.
$apply_flow   = $new_flow( 'apply', 'cart_abandoned' );
$apply_graph  = WorkflowStore::find( $apply_flow )['graph'];
$apply        = fn( int $id ) => rest_do_request( new WP_REST_Request( 'POST', "/yaymatik/v1/workflows/{$id}/apply" ) );
$settings( [ 'cart_tracking' => false ] + $on );
$same_trigger = $apply_graph;
$same_trigger['nodes'][1]['data']['config']['heading'] = 'Changed';
$update( $apply_flow, $same_trigger );
$ok( 200 === $apply( $apply_flow )->get_status(), 'tracking off, active workflow: Update with the same trigger works' );
$new_trigger                              = $same_trigger;
$new_trigger['nodes'][0]['data']['block'] = 'checkout_abandoned';
$drafted                                  = $update( $apply_flow, $new_trigger )->get_data();
$ok( 'cart_tracking_off' === ( $drafted['issues']['draft'][0]['code'] ?? '' ) && [] === $drafted['issues']['live'], 'tracking off: a draft that changes the trigger shows the issue on the draft only' );
$refused = $apply( $apply_flow );
$ok( 400 === $refused->get_status() && 'cart_tracking_off' === ( $refused->get_data()['data']['errors'][0]['code'] ?? '' ), 'tracking off: Update that changes to a cart trigger → 400 cart_tracking_off' );
$settings( [ 'abandon_minutes' => 60 ] + $on );
$ok( 200 === $apply( $apply_flow )->get_status(), 'tracking on: that Update works' );

// customer.is_first_order on a cart.
$first_email = "first@{$domain}";
$due_row( 'firstorder', "email = '{$first_email}'" );
$first_cart  = YayMatik\Workflow\Core\Cart\SavedCart::find( (int) $row( $sw . 'firstorder' )['id'] );
$first_value = ( new YayMatik\Workflow\DataItems\CustomerData() )->fields()['is_first_order']['value'];
$first_cust  = YayMatik\Workflow\Core\Cart\CartCustomer::of( $first_cart );
$ok( true === $first_value( $first_cust ), 'is_first_order on a cart: true with no paid order' );
$paid_order = wc_create_order();
$paid_order->set_billing_email( $first_email );
$paid_order->set_status( 'completed' );
$paid_order->save();
$orders[] = $paid_order->get_id();
$ok( false === $first_value( $first_cust ), 'is_first_order on a cart: false with one paid order' );

// A real send goes through the mail catcher.
$sent_html = [];
$catch     = function ( $short_circuit, $atts ) use ( &$sent_html ) {
    $sent_html[] = $atts;
    return true;
};
add_filter( 'pre_wp_mail', $catch, 10, 2 );
$real_flow = $new_flow( 'real send', 'cart_abandoned', false, '<p>Hi {{ customer.first_name | default: "there" }},</p><p>{{ cart.items }}</p><p>{{ cart.restore_button }}</p>' );
$due_row( 'realsend' );
$sweep();
$real_id  = (int) $row( $sw . 'realsend' )['id'];
$real_run = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$runs_table} WHERE subject = %s AND workflow_id = %d", 'cart:' . $real_id, $real_flow ) );
$cart_runs( $real_id );
as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $real_run ], ActionSchedulerQueue::GROUP );
Runtime::worker()->run( $real_run );
remove_filter( 'pre_wp_mail', $catch );
$mail_body = (string) ( $sent_html[0]['message'] ?? '' );
$html_path = get_temp_dir() . 'yaymatik-check-carts-email.html';
file_put_contents( $html_path, $mail_body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a file for the developer to open.
$ok( 'completed' === $run_row( $real_run )['status'] && false !== strpos( $mail_body, 'Cart total' ) && false !== strpos( $mail_body, RestoreLink::ARG_CART . '=' . $real_id ), "real send: items table and a working restore link; HTML at {$html_path}" );

foreach ( $flows as $flow_id ) {
    WorkflowStore::deactivate( $flow_id );
}
foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT r.id FROM {$runs_table} r JOIN {$table} c ON r.subject = CONCAT('cart:', c.id) WHERE c.session_key LIKE %s OR c.session_key = %s", $wpdb->esc_like( $sw ) . '%', (string) $u ) ) as $run_id ) {
    as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ (int) $run_id ], ActionSchedulerQueue::GROUP );
}
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
