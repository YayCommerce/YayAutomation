<?php
namespace YayMatik\Workflow\Core\Cart;

use YayMatik\Utils\Logger;
use YayMatik\Utils\Secret;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;
use YayMatik\Workflow\Core\Runtime\SystemClock;
use YayMatik\Workflow\Core\Storage\CartStore;
use YayMatik\Workflow\Core\Storage\Settings;

defined( 'ABSPATH' ) || exit;

/** Guest emails typed at checkout: classic checkout through our own endpoint, block checkout through the Store API. */
final class EmailCapture {

    private const AJAX_ACTION   = 'yaymatik_capture_email';
    private const NONCE_ACTION  = 'yaymatik_capture_email';
    private const CAPTURED_KEY  = 'yaymatik_captured';
    private const SESSIONS_MAX  = 3;
    private const EMAIL_MAX     = 190;
    private const NAME_MAX      = 190;
    private const SCRIPT_HANDLE = 'yaymatik-capture-email';

    /** "no cart", "logged in" and "not an email" are ordinary shopper behaviour: not logged. */
    private const LOGGED = [ 'bad nonce', 'not from the store', 'rate limit' ];

    public static function boot(): void {
        // Answers even while tracking or capture is off, so the response never tells a setting apart.
        add_action( 'wc_ajax_' . self::AJAX_ACTION, [ self::class, 'ajax' ] );
        add_filter( 'nonce_user_logged_out', [ self::class, 'nonce_user' ], 10, 2 );
        if ( ! self::enabled() ) {
            return;
        }
        add_action( 'woocommerce_store_api_cart_update_customer_from_request', [ self::class, 'store_api' ], 10, 2 );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
    }

    /** Classic checkout only: the block checkout sends the email through the Store API. */
    public static function enqueue(): void {
        if ( is_user_logged_in() || ! is_checkout() || is_wc_endpoint_url() || has_block( 'woocommerce/checkout' ) ) {
            return;
        }
        wp_enqueue_script( self::SCRIPT_HANDLE, YAYMATIK_PLUGIN_URL . 'assets/js/capture-email.js', [], YAYMATIK_VERSION, true );
        wp_localize_script(
            self::SCRIPT_HANDLE,
            'yayMatikCapture',
            [
                'url'   => \WC_AJAX::get_endpoint( self::AJAX_ACTION ),
                'nonce' => wp_create_nonce( self::NONCE_ACTION ),
            ]
        );
    }

    /**
     * A guest's nonce belongs to their cart session, as WooCommerce's own `woocommerce*` nonces do.
     *
     * @param int|string $uid
     * @param int|string $action
     * @return int|string
     */
    public static function nonce_user( $uid, $action ) {
        // Without it every guest shares one nonce. Checkout isn't page-cached: WooCommerce sets DONOTCACHEPAGE there.
        $session = self::NONCE_ACTION === $action && function_exists( 'WC' ) ? WC()->session : null;
        return $session instanceof \WC_Session_Handler && $session->has_session() && $session->get_customer_id() ? $session->get_customer_id() : $uid;
    }

    /** Always the same `{"success":true}`: the answer never says whether an address is known, valid or kept. */
    public static function ajax(): void {
        if ( ! self::enabled() ) {
            wp_send_json_success();
        }
        if ( false === check_ajax_referer( self::NONCE_ACTION, '_wpnonce', false ) ) {
            self::log( 'bad nonce' );
            wp_send_json_success();
        }
        $email      = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';
        $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
        $headers    = [];
        foreach ( [
            'sec-fetch-site' => 'HTTP_SEC_FETCH_SITE',
            'origin'         => 'HTTP_ORIGIN',
            'referer'        => 'HTTP_REFERER',
        ] as $name => $server ) {
            $headers[ $name ] = isset( $_SERVER[ $server ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $server ] ) ) : '';
        }

        self::log( RequestOrigin::allowed( $headers, home_url() ) ? self::attempt( $email, $first_name ) : 'not from the store' );
        wp_send_json_success();
    }

    /**
     * Block checkout: runs on every address update, so it acts only when the pair changed.
     *
     * @param \WC_Customer $customer
     * @param mixed        $request
     */
    public static function store_api( $customer, $request ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the hook passes it.
        if ( is_user_logged_in() || ! $customer instanceof \WC_Customer || ! WC()->session ) {
            return;
        }
        $pair = [ (string) $customer->get_billing_email(), (string) $customer->get_billing_first_name() ];
        if ( WC()->session->get( self::CAPTURED_KEY ) === $pair ) {
            return;
        }
        $reason = self::attempt( $pair[0], $pair[1] );
        // A limit or a missing cart may pass later: only a final answer marks the pair as done.
        if ( ! in_array( $reason, [ 'rate limit', 'no cart' ], true ) ) {
            WC()->session->set( self::CAPTURED_KEY, $pair );
        }
        self::log( $reason );
    }

    /**
     * @param string $email As typed.
     * @param string $first_name
     * @return string Why it was refused; empty when stored, dropped silently, or nothing was typed.
     */
    private static function attempt( string $email, string $first_name ): string {
        if ( '' === trim( $email ) ) {
            return '';
        }
        if ( is_user_logged_in() ) {
            return 'logged in';
        }
        $session = WC()->session;
        if ( ! $session || ! $session->has_session() || ! WC()->cart || WC()->cart->is_empty() ) {
            return 'no cart';
        }
        $email = UnsubscribeLink::normalize( $email );
        if ( '' === $email || strlen( $email ) > self::EMAIL_MAX ) {
            return 'not an email';
        }
        $key    = (string) $session->get_customer_id();
        $limits = new CaptureLimits( Secret::option( RestoreLink::SECRET_OPTION ), new SystemClock() );
        if ( ! $limits->allow( $key, (string) \WC_Geolocation::get_ip_address() ) ) {
            return 'rate limit';
        }
        // One address from at most three sessions a day: someone typing other people's addresses gains little.
        $row = CartStore::find_by_session( $key );
        if ( ( ! $row || $row['email'] !== $email )
            && CartStore::sessions_with_email( $email, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) >= self::SESSIONS_MAX ) {
            return '';
        }
        CartTracker::capture( $email, mb_substr( trim( $first_name ), 0, self::NAME_MAX ) );
        return '';
    }

    private static function enabled(): bool {
        return Settings::get( 'cart_tracking' ) && Settings::get( 'capture_guest_email' );
    }

    /**
     * Only refusals worth a look, each at most once an hour (anyone can send them); the reason, never the address.
     *
     * @param string $reason
     */
    private static function log( string $reason ): void {
        if ( ! in_array( $reason, self::LOGGED, true ) ) {
            return;
        }
        $flag = 'yaymatik_log_' . str_replace( ' ', '_', $reason );
        if ( get_transient( $flag ) ) {
            return;
        }
        set_transient( $flag, 1, HOUR_IN_SECONDS );
        Logger::error( 'Email capture refused: ' . $reason );
    }
}
