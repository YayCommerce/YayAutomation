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

// Code that reads the database (e.g. a step's list of workflows) gets empty answers unless a test sets its own.
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
$GLOBALS['wpdb'] = new YayMatik\Tests\Support\FakeWpdb();
