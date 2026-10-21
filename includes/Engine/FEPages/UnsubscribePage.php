<?php
namespace YayMatik\Engine\FEPages;

use YayMatik\Utils\SingletonTrait;
use YayMatik\Workflow\Email\UnsubscribeLink;
use YayMatik\Workflow\Storage\UnsubscribeStore;

defined( 'ABSPATH' ) || exit;

/**
 * Where a customer email's unsubscribe link lands: a small page of its own, not wrapped in the
 * theme, so it looks the same on block and classic themes.
 *
 * - GET shows an "Unsubscribe" button: mail scanners open links, and must not unsubscribe anyone.
 * - POST unsubscribes: the button, and mail apps' one-click (`List-Unsubscribe-Post`, RFC 8058).
 *   The signed link is the proof, so there's no nonce (the customer isn't logged in).
 */
class UnsubscribePage {

    use SingletonTrait;

    private const STATE_CONFIRM = 'confirm';
    private const STATE_DONE    = 'done';
    private const STATE_INVALID = 'invalid';
    private const STATE_ERROR   = 'error';

    protected function __construct() {
        add_action( 'template_redirect', [ $this, 'maybe_handle' ], 1 );
    }

    public function maybe_handle(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the signed link is the proof (see class doc).
        if ( ! isset( $_GET[ UnsubscribeLink::ARG_EMAIL ] ) ) {
            return;
        }
        $encoded = sanitize_text_field( wp_unslash( $_GET[ UnsubscribeLink::ARG_EMAIL ] ) );
        $token   = isset( $_GET[ UnsubscribeLink::ARG_TOKEN ] ) ? sanitize_text_field( wp_unslash( $_GET[ UnsubscribeLink::ARG_TOKEN ] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $email = UnsubscribeLink::from_options()->verify( $encoded, $token );
        $post  = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );

        if ( null === $email ) {
            $state = self::STATE_INVALID;
        } elseif ( $post ) {
            $state = UnsubscribeStore::add( $email ) ? self::STATE_DONE : self::STATE_ERROR;
        } else {
            $state = UnsubscribeStore::has( $email ) ? self::STATE_DONE : self::STATE_CONFIRM;
        }

        $codes = [
            self::STATE_INVALID => 400,
            self::STATE_ERROR   => 500,
        ];
        status_header( $codes[ $state ] ?? 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
        header( 'X-Robots-Tag: noindex, nofollow' );
        // The token stays out of Referer headers when the customer follows the store link.
        header( 'Referrer-Policy: no-referrer' );
        $this->render( $state );
        exit;
    }

    /**
     * @param string $state One of the STATE_* constants.
     */
    private function render( string $state ): void {
        // Stored escaped, like the site title everywhere; decoded once, escaped on output.
        $store = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
        $texts = self::texts( $store )[ $state ];

        $base    = sanitize_hex_color( (string) get_option( 'woocommerce_email_base_color', '' ) );
        $base    = $base ? $base : '#2271b1';
        $on_base = function_exists( 'wc_light_or_dark' ) ? wc_light_or_dark( $base, '#1d2327', '#ffffff' ) : '#ffffff';
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $texts['heading'] . ' – ' . $store ); ?></title>
<style>
    body { margin: 0; padding: 48px 16px; background: #f6f7f7; color: #1d2327; font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
    main { max-width: 440px; margin: 0 auto; padding: 32px 28px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; text-align: center; }
    .store { margin: 0 0 24px; color: #50575e; font-size: 14px; font-weight: 600; letter-spacing: .02em; }
    h1 { margin: 0 0 12px; font-size: 22px; line-height: 1.3; }
    p { margin: 0 0 24px; color: #3c434a; }
    button { padding: 10px 24px; border: 0; border-radius: 4px; background: <?php echo esc_html( $base ); ?>; color: <?php echo esc_html( $on_base ); ?>; font: inherit; font-weight: 600; cursor: pointer; }
    button:focus-visible, a:focus-visible { outline: 2px solid <?php echo esc_html( $base ); ?>; outline-offset: 2px; }
    a { color: #50575e; font-size: 14px; }
</style>
</head>
<body>
<main>
    <p class="store"><?php echo esc_html( $store ); ?></p>
    <h1><?php echo esc_html( $texts['heading'] ); ?></h1>
    <p><?php echo esc_html( $texts['body'] ); ?></p>
        <?php if ( self::STATE_CONFIRM === $state ) : ?>
    <form method="post">
        <button type="submit"><?php esc_html_e( 'Unsubscribe', 'yaymatik' ); ?></button>
    </form>
        <?php else : ?>
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>">
            <?php
            /* translators: %s: store name */
            echo esc_html( sprintf( __( 'Go to %s', 'yaymatik' ), $store ) );
            ?>
    </a>
        <?php endif; ?>
</main>
</body>
</html>
        <?php
    }

    /**
     * Heading and text of each state.
     *
     * @param string $store Store name, plain text.
     */
    private static function texts( string $store ): array {
        return [
            self::STATE_CONFIRM => [
                'heading' => __( 'Unsubscribe from these emails?', 'yaymatik' ),
                /* translators: %s: store name */
                'body'    => sprintf( __( 'You’ll stop getting emails like this one from %s. Emails about your orders, like receipts and shipping updates, still arrive.', 'yaymatik' ), $store ),
            ],
            self::STATE_DONE    => [
                'heading' => __( 'You’re unsubscribed', 'yaymatik' ),
                /* translators: %s: store name */
                'body'    => sprintf( __( 'You won’t get emails like this one from %s any more.', 'yaymatik' ), $store ),
            ],
            self::STATE_INVALID => [
                'heading' => __( 'This link is no longer valid', 'yaymatik' ),
                /* translators: %s: store name */
                'body'    => sprintf( __( 'Use the unsubscribe link in the latest email you got from %s.', 'yaymatik' ), $store ),
            ],
            self::STATE_ERROR   => [
                'heading' => __( 'Something went wrong', 'yaymatik' ),
                'body'    => __( 'We couldn’t unsubscribe you just now. Please try again in a moment.', 'yaymatik' ),
            ],
        ];
    }
}
