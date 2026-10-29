<?php
namespace YayMatik\Utils;

defined( 'ABSPATH' ) || exit;

/** Random secrets the plugin keeps in its own options. */
final class Secret {

    /**
     * The stored secret; creates it when missing.
     *
     * @param string $name
     */
    public static function option( string $name ): string {
        // The plugin's own secret, not `wp_salt()`: a salt change must not break links already sent.
        $secret = (string) get_option( $name, '' );
        if ( '' !== $secret ) {
            return $secret;
        }
        $secret = bin2hex( random_bytes( 32 ) );
        // Another request may have created it first: keep theirs, it may already have signed something.
        if ( ! add_option( $name, $secret, '', false ) ) {
            $secret = (string) get_option( $name, '' );
        }
        return $secret;
    }
}
