<?php
namespace YayMatik\Engine\Register;

use YayMatik\Utils\SingletonTrait;

/** Loads the built admin app. */
class RegisterProd {
    use SingletonTrait;

    protected function __construct() {
        add_action( 'init', [ $this, 'register_all_scripts' ] );
    }

    public function register_all_scripts() {
        wp_register_script( ScriptName::PAGE_ADMIN, YAYMATIK_PLUGIN_URL . 'assets/dist/admin-page/main.js', ScriptName::ADMIN_DEPS, YAYMATIK_VERSION, true );
    }
}
