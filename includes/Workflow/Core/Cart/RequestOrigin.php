<?php
namespace YayMatik\Workflow\Core\Cart;

defined( 'ABSPATH' ) || exit;

/** Whether a request came from the store's own pages: the CSRF check of an endpoint without a nonce. */
final class RequestOrigin {

    /**
     * `Sec-Fetch-Site` when the browser sends it; else `Origin`, else `Referer`, by host and port. None → refused.
     *
     * @param array  $headers Lowercase names: sec-fetch-site, origin, referer.
     * @param string $home_url
     */
    public static function allowed( array $headers, string $home_url ): bool {
        $site = strtolower( trim( (string) ( $headers['sec-fetch-site'] ?? '' ) ) );
        if ( '' !== $site ) {
            return 'same-origin' === $site;
        }
        foreach ( [ 'origin', 'referer' ] as $name ) {
            $value = trim( (string) ( $headers[ $name ] ?? '' ) );
            if ( '' !== $value ) {
                $from = self::host_port( $value );
                return null !== $from && self::host_port( $home_url ) === $from;
            }
        }
        return false;
    }

    /**
     * `host:port`, the default port filled in; null without a host (e.g. `Origin: null`).
     *
     * @param string $url
     */
    private static function host_port( string $url ): ?string {
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) ) {
            return null;
        }
        $scheme = strtolower( $parts['scheme'] ?? '' );
        $port   = $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 );
        return strtolower( $parts['host'] ) . ':' . (int) $port;
    }
}
