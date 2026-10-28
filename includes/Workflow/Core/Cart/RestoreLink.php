<?php
namespace YayMatik\Workflow\Core\Cart;

use YayMatik\Utils\Secret;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;

defined( 'ABSPATH' ) || exit;

/** Signed links that refill a saved cart; valid while its row exists and its cycle hasn't restarted. */
final class RestoreLink {

    public const SECRET_OPTION = 'yaymatik_cart_secret';

    public const ARG_CART = 'yaymatik_cart';

    /** @var string */
    private $secret;

    /**
     * @param string $secret
     */
    public function __construct( string $secret ) {
        $this->secret = $secret;
    }

    /** With the stored secret, created first when missing. */
    public static function from_options(): self {
        return new self( Secret::option( self::SECRET_OPTION ) );
    }

    /**
     * @param int    $id         The cart row's id.
     * @param string $created_at The row's `created_at`: a new cycle gets a new one, so older links stop working.
     */
    public function url( int $id, string $created_at ): string {
        return add_query_arg(
            [
                self::ARG_CART             => $id,
                UnsubscribeLink::ARG_TOKEN => $this->token( $id, $created_at ),
            ],
            home_url( '/' )
        );
    }

    /**
     * @param int    $id
     * @param string $created_at From the row with that id.
     * @param string $token
     */
    public function verify( int $id, string $created_at, string $token ): bool {
        return $id > 0 && '' !== $token && hash_equals( $this->token( $id, $created_at ), $token );
    }

    /**
     * @param int    $id
     * @param string $created_at
     */
    private function token( int $id, string $created_at ): string {
        return UnsubscribeLink::base64url_encode( hash_hmac( 'sha256', 'cart|' . $id . '|' . $created_at, $this->secret, true ) );
    }
}
