<?php
/**
 * PHPUnit bootstrap: plain unit tests, WordPress is not loaded.
 *
 * Classes load straight from includes/ (PSR-4), so the plugin's own composer install isn't needed
 * (CI can't reach the private admin-shell package). Code that needs real WordPress or WooCommerce
 * is checked end to end with wp-cli instead.
 */

require_once __DIR__ . '/../dev/tools/phpunit/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
define( 'YAYMATIK_PLUGIN_URL', 'https://example.test/wp-content/plugins/yaymatik/' );

spl_autoload_register(
    static function ( string $class_name ): void {
        // Test helpers first (YayMatik\Tests\ → tests/), then the plugin (YayMatik\ → includes/).
        $roots = [
            'YayMatik\\Tests\\' => __DIR__ . '/',
            'YayMatik\\'        => __DIR__ . '/../includes/',
        ];
        foreach ( $roots as $prefix => $dir ) {
            if ( 0 === strpos( $class_name, $prefix ) ) {
                break;
            }
        }
        if ( 0 !== strpos( $class_name, $prefix ) ) {
            return;
        }
        $file = $dir . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
        if ( is_file( $file ) ) {
            require_once $file;
        }
    }
);

require_once __DIR__ . '/stubs.php';
