<?php
namespace YayMatik\Engine\FEPages;

use YayMatik\Utils\SingletonTrait;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;
use YayMatik\Workflow\Core\Runtime\Cancellation;
use YayMatik\Workflow\Core\Storage\CartStore;
use YayMatik\Workflow\Core\Storage\Settings;
use YayMatik\Workflow\Core\Storage\UnsubscribeStore;

defined( 'ABSPATH' ) || exit;

/** The unsubscribe link's page, outside the theme so it looks the same on block and classic themes. */
class UnsubscribePage {

    use SingletonTrait;

    private const STATE_CONFIRM = 'confirm';
    private const STATE_DONE    = 'done';
    private const STATE_INVALID = 'invalid';
    private const STATE_ERROR   = 'error';

    /** What `request()` returns for a POST that may unsubscribe. */
    public const UNSUBSCRIBE = 'unsubscribe';

    protected function __construct() {
        add_action( 'template_redirect', [ $this, 'maybe_handle' ], 1 );
    }

    /** The email's signed link; a POST also needs the button's nonce, except a mail app's one-click. */
    public function maybe_handle(): void {
        // A mail app's one-click (RFC 8058) posts from no browser and no session: no nonce can exist, the HMAC token is the proof.
        if ( ! isset( $_GET[ UnsubscribeLink::ARG_EMAIL ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- an email link.
            return;
        }
        $encoded = sanitize_text_field( wp_unslash( $_GET[ UnsubscribeLink::ARG_EMAIL ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- an email link.
        $token   = isset( $_GET[ UnsubscribeLink::ARG_TOKEN ] ) ? sanitize_text_field( wp_unslash( $_GET[ UnsubscribeLink::ARG_TOKEN ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- an email link.
        $post    = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
        $body    = [];
        foreach ( [ 'List-Unsubscribe', '_wpnonce' ] as $field ) {
            // Checked in request(): the nonce, or the one-click body with the token.
            $body[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        $email   = UnsubscribeLink::from_options()->verify( $encoded, $token );
        $request = self::request( $email, $post, $body );
        if ( self::STATE_INVALID === $request ) {
            $state = self::STATE_INVALID;
        } elseif ( self::UNSUBSCRIBE === $request ) {
            $state = self::unsubscribe( $email ) ? self::STATE_DONE : self::STATE_ERROR;
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
        $this->render( $state, (string) $email );
        exit;
    }

    /**
     * What the request may do: `STATE_INVALID`, `UNSUBSCRIBE` or `STATE_CONFIRM` (show the button).
     *
     * @param string|null $email Null when the link's token fails.
     * @param bool        $post
     * @param string[]    $body
     */
    public static function request( ?string $email, bool $post, array $body ): string {
        // GET only shows the button: mail scanners open links and must not unsubscribe anyone.
        if ( null === $email ) {
            return self::STATE_INVALID;
        }
        if ( ! $post ) {
            return self::STATE_CONFIRM;
        }
        if ( 'One-Click' === ( $body['List-Unsubscribe'] ?? '' ) || wp_verify_nonce( $body['_wpnonce'] ?? '', self::nonce_action( $email ) ) ) {
            return self::UNSUBSCRIBE;
        }
        return self::STATE_CONFIRM;
    }

    /**
     * @param string $email
     */
    private static function nonce_action( string $email ): string {
        return 'yaymatik_unsubscribe_' . md5( $email );
    }

    /**
     * Records the address and stops its carts' waiting runs, so none later makes a coupon nobody gets.
     *
     * @param string $email
     */
    public static function unsubscribe( string $email ): bool {
        if ( ! UnsubscribeStore::add( $email ) ) {
            return false;
        }
        $carts = CartStore::for_email( UnsubscribeLink::normalize( $email ) );
        Cancellation::carts( array_map( 'intval', array_column( $carts, 'id' ) ), Cancellation::unsubscribed() );
        return true;
    }

    /**
     * @param string $state One of the STATE_* constants.
     * @param string $email Empty when the link is invalid.
     */
    private function render( string $state, string $email ): void {
        $store = self::store_name();
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
    <p><?php echo nl2br( esc_html( $texts['body'] ) ); ?></p>
        <?php if ( self::STATE_CONFIRM === $state ) : ?>
    <form method="post">
            <?php wp_nonce_field( self::nonce_action( $email ) ); ?>
        <button type="submit"><?php esc_html_e( 'Unsubscribe', 'yaymatik-automation-for-woocommerce' ); ?></button>
    </form>
        <?php else : ?>
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>">
            <?php
            /* translators: %s: store name */
            echo esc_html( sprintf( __( 'Go to %s', 'yaymatik-automation-for-woocommerce' ), $store ) );
            ?>
    </a>
        <?php endif; ?>
</main>
</body>
</html>
        <?php
    }

    /** The done state's text when the unsubscribe text setting is empty. */
    public static function default_done_body(): string {
        /* translators: %s: store name */
        return sprintf( __( 'You won’t get emails like this one from %s any more.', 'yaymatik-automation-for-woocommerce' ), self::store_name() );
    }

    private static function store_name(): string {
        // Stored escaped, like the site title everywhere; decoded once, escaped on output.
        return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
    }

    /**
     * Heading and text of each state.
     *
     * @param string $store Plain text.
     */
    private static function texts( string $store ): array {
        $done = (string) Settings::get( 'unsubscribe_text' );
        return [
            self::STATE_CONFIRM => [
                'heading' => __( 'Unsubscribe from these emails?', 'yaymatik-automation-for-woocommerce' ),
                /* translators: %s: store name */
                'body'    => sprintf( __( 'You’ll stop getting emails like this one from %s. Emails about your orders, like receipts and shipping updates, still arrive.', 'yaymatik-automation-for-woocommerce' ), $store ),
            ],
            self::STATE_DONE    => [
                'heading' => __( 'You’re unsubscribed', 'yaymatik-automation-for-woocommerce' ),
                'body'    => '' !== $done ? $done : self::default_done_body(),
            ],
            self::STATE_INVALID => [
                'heading' => __( 'This link is no longer valid', 'yaymatik-automation-for-woocommerce' ),
                /* translators: %s: store name */
                'body'    => sprintf( __( 'Use the unsubscribe link in the latest email you got from %s.', 'yaymatik-automation-for-woocommerce' ), $store ),
            ],
            self::STATE_ERROR   => [
                'heading' => __( 'Something went wrong', 'yaymatik-automation-for-woocommerce' ),
                'body'    => __( 'We couldn’t unsubscribe you just now. Please try again in a moment.', 'yaymatik-automation-for-woocommerce' ),
            ],
        ];
    }
}
