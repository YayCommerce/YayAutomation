<?php
namespace YayMatik\Workflow\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Signed unsubscribe links for customer emails. The link carries the address base64url-encoded
 * (never in plain text) and an HMAC of it; a valid pair is the only proof needed, so links never
 * expire. The secret is the plugin's own (not `wp_salt()`), so a salt change doesn't break links
 * already sent.
 */
final class UnsubscribeLink {

    public const SECRET_OPTION = 'yaymatik_unsub_secret';

    /** Query args of the link, on the home URL. */
    public const ARG_EMAIL = 'yaymatik_unsub';
    public const ARG_TOKEN = 'yaymatik_token';

    /** @var string */
    private $secret;

    /**
     * @param string $secret HMAC key.
     */
    public function __construct( string $secret ) {
        $this->secret = $secret;
    }

    /** With the stored secret, created first when missing. */
    public static function from_options(): self {
        return new self( self::create_secret() );
    }

    /** The stored secret; creates it when missing (install, or an option deleted later). */
    public static function create_secret(): string {
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
     * @param string $email Address.
     */
    public static function normalize( string $email ): string {
        $email = strtolower( trim( $email ) );
        return is_email( $email ) ? $email : '';
    }

    /**
     * Link for an address; empty when it isn't an email.
     *
     * @param string $email Address.
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
     * @param string $encoded The address arg.
     * @param string $token   The token arg.
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
     * @param string $data Bytes.
     */
    public static function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- link encoding, not obfuscation.
    }

    /**
     * Bytes back from `base64url_encode()`; null when it isn't valid base64url.
     *
     * @param string $text Encoded text.
     */
    public static function base64url_decode( string $text ): ?string {
        if ( '' === $text || ! preg_match( '/^[A-Za-z0-9_-]+$/', $text ) ) {
            return null;
        }
        $data = base64_decode( strtr( $text, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- link encoding, not obfuscation.
        return false === $data ? null : $data;
    }
}
