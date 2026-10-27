<?php
namespace YayMatik\Workflow\Core\Cart;

use YayMatik\Workflow\Core\Email\UnsubscribeLink;
use YayMatik\Workflow\Core\Storage\CartStore;
use YayMatik\Workflow\Core\Storage\Settings;

defined( 'ABSPATH' ) || exit;

/** Keeps the `carts` row of each WooCommerce session in step with its cart, login and orders. */
final class CartTracker {

    private const HASH_KEY = 'yaymatik_cart_hash';

    /** A cart or checkout view refreshes `last_activity_at` at most this often. */
    private const VIEW_EVERY = 5 * MINUTE_IN_SECONDS;

    /** @var bool The cart was recalculated or emptied in this request. */
    private static $touched = false;

    /** @var bool An order was created from the cart in this request. */
    private static $ordered = false;

    public static function boot(): void {
        // Not a store-front hook: keeps rows right for later triggers even while tracking is off.
        add_action( 'woocommerce_order_status_changed', [ self::class, 'order_status_changed' ], 10, 4 );
        if ( ! Settings::get( 'cart_tracking' ) ) {
            return;
        }
        add_action( 'woocommerce_cart_updated', [ self::class, 'cart_updated' ] );
        add_action( 'woocommerce_cart_emptied', [ self::class, 'cart_updated' ] );
        add_action( 'template_redirect', [ self::class, 'view' ] );
        // Before WooCommerce saves the session (20), so the new hash is saved with it.
        add_action( 'shutdown', [ self::class, 'shutdown' ], 10 );
        add_action( 'woocommerce_guest_session_to_user_id', [ self::class, 'login' ], 10, 2 );
        add_action( 'woocommerce_checkout_order_created', [ self::class, 'order_created' ] );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ self::class, 'order_created' ] );
    }

    public static function cart_updated(): void {
        self::$touched = true;
    }

    /** Cart or checkout page: refreshes this session's existing row, never creates one. */
    public static function view(): void {
        if ( ( ! is_cart() && ! is_checkout() ) || is_order_received_page() ) {
            return;
        }
        $key = self::session_key();
        $row = '' === $key ? null : CartStore::find_by_session( $key );
        if ( ! $row ) {
            return;
        }
        $now    = current_time( 'mysql', true );
        $fields = [];
        if ( is_checkout() && is_user_logged_in() && null === $row['checkout_at'] && WC()->cart && ! WC()->cart->is_empty() ) {
            $fields['checkout_at'] = $now;
        }
        if ( $fields || strtotime( $row['last_activity_at'] . ' UTC' ) < time() - self::VIEW_EVERY ) {
            $fields['last_activity_at'] = $now;
            CartStore::touch( $key, $fields );
        }
    }

    public static function shutdown(): void {
        if ( ! self::$touched || ! self::front_end() || ! WC()->session || ! WC()->session->has_session() ) {
            return;
        }
        $snapshot = CartSnapshot::from_wc();
        if ( ! $snapshot ) {
            return;
        }
        if ( ! $snapshot->lines() ) {
            if ( '' !== (string) WC()->session->get( self::HASH_KEY ) ) {
                self::emptied();
            }
            return;
        }
        self::record( $snapshot, 'cart' );
    }

    /**
     * Writes the session's row from the cart.
     *
     * @param CartSnapshot $snapshot Not empty.
     * @param string       $reason   `cart` writes only when the lines changed; any other activity always writes.
     */
    public static function record( CartSnapshot $snapshot, string $reason ): void {
        $key = self::session_key();
        if ( '' === $key ) {
            return;
        }
        $hash    = $snapshot->hash();
        $changed = WC()->session->get( self::HASH_KEY ) !== $hash;
        if ( 'cart' === $reason && ! $changed ) {
            return;
        }
        $row   = CartStore::find_by_session( $key );
        $reset = $row && CartStore::ORDERED === $row['status'] && $changed;
        if ( $reset ) {
            CartStore::reset_cycle( (int) $row['id'] );
        }
        $now     = current_time( 'mysql', true );
        $fields  = [
            'items'            => (string) wp_json_encode( $snapshot->lines() ),
            'total'            => $snapshot->total(),
            'currency'         => $snapshot->currency(),
            'last_activity_at' => $now,
        ];
        $user_id = get_current_user_id();
        if ( $user_id ) {
            list( $email, $first_name ) = self::account( $user_id );
            $fields                    += [
                'user_id'    => $user_id,
                'email'      => $email,
                'first_name' => $first_name,
            ];
            // A session's first request may open checkout directly, before any row exists for view() to update.
            if ( ( ! $row || $reset || null === $row['checkout_at'] ) && is_checkout() && ! is_order_received_page() ) {
                $fields['checkout_at'] = $now;
            }
        }
        CartStore::upsert( $key, $fields );
        WC()->session->set( self::HASH_KEY, $hash );
    }

    /** The cart is empty: its row goes, unless an order was made from it. */
    public static function emptied(): void {
        // Logout empties the cart while the session key is still the user id: that row must stay.
        if ( did_action( 'wp_logout' ) ) {
            return;
        }
        $key = self::session_key();
        if ( '' === $key ) {
            return;
        }
        $row = CartStore::find_by_session( $key );
        if ( $row && 0 === (int) $row['order_id'] && ! self::$ordered ) {
            CartStore::delete_by_session( $key );
        }
        WC()->session->set( self::HASH_KEY, '' );
    }

    /**
     * @param string $guest_key
     * @param string $user_key  The user id.
     */
    public static function login( $guest_key, $user_key ): void {
        $user_id = (int) $user_key;
        if ( $user_id <= 0 ) {
            return;
        }
        list( $email, $first_name ) = self::account( $user_id );
        CartStore::rekey( (string) $guest_key, $user_id, $email, $first_name );
    }

    /**
     * Written at once, not at shutdown: a gateway may mark the order paid within this request.
     *
     * @param \WC_Order $order
     */
    public static function order_created( $order ): void {
        $key = self::session_key();
        if ( '' === $key || ! $order instanceof \WC_Order || $order->get_id() <= 0 ) {
            return;
        }
        self::$ordered = true;
        $snapshot      = CartSnapshot::from_wc();
        if ( $snapshot && $snapshot->lines() ) {
            self::record( $snapshot, 'order' );
        }
        CartStore::link_order( $key, $order->get_id(), UnsubscribeLink::normalize( $order->get_billing_email() ), $order->get_billing_first_name() );
    }

    /**
     * Paid: its own cart is ordered, and (not for free orders) every active cart with the billing email last active before it.
     *
     * @param int       $order_id
     * @param string    $from
     * @param string    $to
     * @param \WC_Order $order
     */
    public static function order_status_changed( $order_id, $from, $to, $order ): void {
        // An id of 0 would match every cart without an order.
        if ( (int) $order_id <= 0 || ! in_array( $to, [ 'processing', 'completed', 'on-hold' ], true ) || ! $order instanceof \WC_Order ) {
            return;
        }
        CartStore::mark_ordered_by_order( (int) $order_id );
        $email   = UnsubscribeLink::normalize( $order->get_billing_email() );
        $created = $order->get_date_created();
        if ( '' === $email || ! $created || (float) $order->get_total( 'edit' ) <= 0 ) {
            return;
        }
        CartStore::mark_ordered_by_email( $email, gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) );
    }

    /**
     * Email and first name of an account, for its cart.
     *
     * @param int $user_id
     * @return string[] [ email, first name ]
     */
    private static function account( int $user_id ): array {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return [ '', '' ];
        }
        $first_name = (string) get_user_meta( $user_id, 'billing_first_name', true );
        return [ UnsubscribeLink::normalize( $user->user_email ), '' !== $first_name ? $first_name : (string) $user->first_name ];
    }

    private static function session_key(): string {
        $session = function_exists( 'WC' ) ? WC()->session : null;
        return $session ? (string) $session->get_customer_id() : '';
    }

    /** Store-front requests, AJAX and the Store API included; not cron, wp-cli or wp-admin. */
    private static function front_end(): bool {
        return ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ( ! is_admin() || wp_doing_ajax() );
    }
}
