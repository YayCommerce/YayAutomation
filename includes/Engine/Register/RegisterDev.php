<?php
namespace YayAutomation\Engine\Register;

use YayAutomation\Utils\SingletonTrait;
use YayAutomation\Engine\Register\ScriptName;

/**
 * Register in Development Mode
 * Will get deleted in production
 */
class RegisterDev {
    use SingletonTrait;

    /** Hooks Initialization */
    protected function __construct() {
        add_action( 'admin_footer', [ $this, 'render_dev_refresh' ], 5 );

        add_action( 'init', [ $this, 'register_all_scripts' ] );
    }

    public function render_dev_refresh() {
        $port = defined( 'YAY_AUTOMATION_VITE_PORT' ) ? \YAY_AUTOMATION_VITE_PORT : 3020;
        echo '<script type="module">
        import RefreshRuntime from "http://localhost:' . (int) $port . '/@react-refresh"
        RefreshRuntime.injectIntoGlobalHook(window)
        window.$RefreshReg$ = () => {}
        window.$RefreshSig$ = () => (type) => type
        window.__vite_plugin_react_preamble_installed__ = true
        </script>';
    }

    public function register_all_scripts() {
        $port = defined( 'YAY_AUTOMATION_VITE_PORT' ) ? \YAY_AUTOMATION_VITE_PORT : 3020;
        $deps = [ 'react', 'react-dom', 'wp-hooks', 'wp-i18n' ];

        wp_register_script( ScriptName::PAGE_ADMIN, 'http://localhost:' . $port . '/main.tsx', $deps, null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
    }
}
