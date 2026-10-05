<?php
namespace YayAutomation\Engine\Register;

use YayAutomation\Utils\SingletonTrait;
use YayAutomation\Engine\Register\ScriptName;

/** Register in Production Mode */
class RegisterProd {
    use SingletonTrait;

    /** Hooks Initialization */
    protected function __construct() {
        add_action( 'init', [ $this, 'register_all_scripts' ] );
    }

    public function register_all_scripts() {
        $deps = [ 'react', 'react-dom', 'wp-hooks', 'wp-i18n' ];

        wp_register_script( ScriptName::PAGE_ADMIN, YAY_AUTOMATION_PLUGIN_URL . 'assets/dist/admin-page/main.js', $deps, YAY_AUTOMATION_VERSION, true );
    }
}
