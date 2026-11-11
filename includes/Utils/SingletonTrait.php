<?php
namespace YayMatik\Utils;

defined( 'ABSPATH' ) || exit;

trait SingletonTrait {
    private static $instance;

    protected function __construct() { }

    public static function get_instance( ...$args ) {
        $class = get_called_class();
        if ( ! $class::$instance ) {
            $class::$instance = new $class( ...$args );
        }

        return $class::$instance;
    }

    protected function __clone() { }

    public function __wakeup() {
        throw new \Exception( 'Cannot unserialize a singleton.' );
    }
}
