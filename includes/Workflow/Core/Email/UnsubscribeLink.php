<?php
namespace YayMatik\Workflow\Core\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Signed unsubscribe links for customer emails; they never expire.
 */
final class UnsubscribeLink {

    public const SECRET_OPTION = 'yaymatik_unsub_secret';

    public const ARG_EMAIL = 'yaymatik_unsub';
    public const ARG_TOKEN = 'yaymatik_token';

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
        return new self( self::create_secret() );
    }

    /** The stored secret; creates it when missing. */
    public static function create_secret(): string {
        // The plugin's own secret, not `wp_salt()`: a salt change must not break links already sent.
        $secret = (string) get_option( self::SECRET_OPTION, '' );
        if ( '' !== $secret ) {
            return $secret;
        }
        $secret = bin2hex( random_bytes( 32 ) );
        // Another request may have created it first: keep theirs, links already use it.
        if ( ! add_option( self::SECRET_OPTION, $secret, '', false ) ) {
            $secret = (string) get_option( self::SECRET_OPTION, '' );
        }
        return $secret;
    }

    /**
     * The address as stored and signed: trimmed, lowercased. Empty when it isn't an email.
     *
     * @param string $email
     */
    public static function normalize( string $email ): string {
        $email = strtolower( trim( $email ) );
        return is_email( $email ) ? $email : '';
    }

    /**
     * Link for an address; empty when it isn't an email.
     *
     * @param string $email
     */
    public function url( string $email ): string {
        $email = self::normalize( $email );
        if ( '' === $email ) {
            return '';
        }
        return add_query_arg(
            [
                self::ARG_EMAIL => self::base64url_encode( $email ),
                self::ARG_TOKEN => $this->token( $email ),
            ],
            home_url( '/' )
        );
    }

    /**
     * The address a link's args stand for, or null when they don't match.
     *
     * @param string $encoded
     * @param string $token
     */
    public function verify( string $encoded, string $token ): ?string {
        $email = self::normalize( (string) self::base64url_decode( $encoded ) );
        if ( '' === $email || '' === $token ) {
            return null;
        }
        return hash_equals( $this->token( $email ), $token ) ? $email : null;
    }

    /**
     * @param string $email Normalized address.
     */
    public function token( string $email ): string {
        return self::base64url_encode( hash_hmac( 'sha256', $email, $this->secret, true ) );
    }

    /**
     * URL-safe base64 without padding.
     *
     * @param string $data
     */
    public static function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- link encoding, not obfuscation.
    }

    /**
     * Bytes back from `base64url_encode()`; null when it isn't valid base64url.
     *
     * @param string $text
     */
    public static function base64url_decode( string $text ): ?string {
        if ( '' === $text || ! preg_match( '/^[A-Za-z0-9_-]+$/', $text ) ) {
            return null;
        }
        $data = base64_decode( strtr( $text, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- link encoding, not obfuscation.
        return false === $data ? null : $data;
    }
}
