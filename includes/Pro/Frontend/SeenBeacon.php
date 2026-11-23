<?php
namespace YayMatik\Pro\Frontend;

use YayMatik\Pro\Workflow\Core\Session\SessionSignals;
use YayMatik\Workflow\Core\Cart\RequestOrigin;

defined( 'ABSPATH' ) || exit;

/**
 * A storefront page tells the store a signed-in person is here, even when a page cache served it without PHP:
 * the beacon's request confirms a pending sign-in on `init` and counts as a visit for a return.
 */
final class SeenBeacon {

    public const ACTION = 'yaymatik_seen';

    private const HANDLE = 'yaymatik-seen';

    public static function register(): void {
        // Signed in only: the person comes from the auth cookie, never from the request body.
        add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'handle' ] );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
    }

    public static function enqueue(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }
        wp_enqueue_script(
            self::HANDLE,
            plugins_url( 'includes/Pro/Frontend/seen.js', YAYMATIK_FILE ),
            [],
            YAYMATIK_VERSION,
            [
                'in_footer' => true,
                'strategy'  => 'defer',
            ]
        );
        wp_add_inline_script( self::HANDLE, 'var yayMatikSeen = ' . wp_json_encode( [ 'url' => admin_url( 'admin-ajax.php?action=' . self::ACTION ) ] ) . ';', 'before' );
    }

    /** No nonce: a cached page can't hold a fresh one. A request another site sends is refused (WordPress's auth cookies have no SameSite). */
    public static function handle(): void {
        $headers = [];
        foreach ( [
            'sec-fetch-site' => 'HTTP_SEC_FETCH_SITE',
            'origin'         => 'HTTP_ORIGIN',
            'referer'        => 'HTTP_REFERER',
        ] as $name => $server ) {
            $headers[ $name ] = isset( $_SERVER[ $server ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $server ] ) ) : '';
        }
        // None of them: not a browser's cross-site request, which always sends one.
        if ( array_filter( $headers ) && ! RequestOrigin::allowed( $headers, home_url() ) ) {
            wp_die( '', '', [ 'response' => 403 ] );
        }
        $signals = SessionSignals::instance();
        if ( $signals->subscribed() ) {
            $signals->visit( get_current_user_id() );
        }
        wp_die( '', '', [ 'response' => 204 ] );
    }
}
