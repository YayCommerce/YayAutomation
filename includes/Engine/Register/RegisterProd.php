<?php
namespace YayMatik\Engine\Register;

use YayMatik\Utils\SingletonTrait;

/** Register in Production Mode */
class RegisterProd {
    use SingletonTrait;

    /** Hooks Initialization */
    protected function __construct() {
        add_action( 'init', [ $this, 'register_all_scripts' ] );
    }

    public function register_all_scripts() {
        $deps = [ 'react', 'react-dom', 'wp-hooks', 'wp-i18n' ];

        wp_register_script( ScriptName::PAGE_ADMIN, YAYMATIK_PLUGIN_URL . 'assets/dist/admin-page/main.js', $deps, YAYMATIK_VERSION, true );
    }
}
