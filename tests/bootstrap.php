<?php
/**
 * PHPUnit bootstrap, without WordPress. Classes load straight from includes/ (PSR-4): CI can't reach
 * the private admin-shell package the plugin's own composer install needs.
 */

require_once __DIR__ . '/../dev/tools/phpunit/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
define( 'YAYMATIK_PLUGIN_URL', 'https://example.test/wp-content/plugins/yaymatik/' );
define( 'YAYMATIK_VERSION', '0.0.0' );
define( 'YAYMATIK_FILE', '/var/www/wp-content/plugins/yaymatik-pro/yaymatik-pro.php' );
define( 'YAYMATIK_BASE_NAME', 'yaymatik-pro/yaymatik-pro.php' );

// YAYMATIK_EDITION=lite: tests run as the free plugin, where includes/Pro doesn't ship.
define( 'YAYMATIK_TEST_LITE', 'lite' === getenv( 'YAYMATIK_EDITION' ) );

spl_autoload_register(
    static function ( string $class_name ): void {
        $roots = [
            'YayMatik\\Tests\\' => __DIR__ . '/',
            'YayMatik\\'        => __DIR__ . '/../includes/',
        ];
        foreach ( $roots as $prefix => $dir ) {
            if ( 0 === strpos( $class_name, $prefix ) ) {
                break;
            }
        }
        if ( 0 !== strpos( $class_name, $prefix ) || ( YAYMATIK_TEST_LITE && 0 === strpos( $class_name, 'YayMatik\\Pro\\' ) ) ) {
            return;
        }
        $file = $dir . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
        if ( is_file( $file ) ) {
            require_once $file;
        }
    }
);

require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/Support/admin-shell.php';

// Code that reads the database gets empty answers unless a test sets its own.
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
$GLOBALS['wpdb'] = new YayMatik\Tests\Support\FakeWpdb();

if ( ! YAYMATIK_TEST_LITE ) {
    // A license that allows Pro, only while Boot reads it: tests reset the options.
    $GLOBALS['yaymatik_test_options'] = YayMatik\Tests\Support\ProLicense::active();
    YayMatik\Pro\Boot::register();
    $GLOBALS['yaymatik_test_options'] = [];
}
