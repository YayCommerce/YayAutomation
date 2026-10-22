<?php
namespace YayMatik\Engine\Register;

use YayMatik\Utils\SingletonTrait;

/** Loads the admin app from the Vite dev server; not shipped. */
class RegisterDev {
    use SingletonTrait;

    protected function __construct() {
        add_action( 'admin_footer', [ $this, 'render_dev_refresh' ], 5 );

        add_action( 'init', [ $this, 'register_all_scripts' ] );
    }

    public function render_dev_refresh() {
        $port = defined( 'YAYMATIK_VITE_PORT' ) ? \YAYMATIK_VITE_PORT : 3020;
        echo '<script type="module">
        import RefreshRuntime from "http://localhost:' . (int) $port . '/@react-refresh"
        RefreshRuntime.injectIntoGlobalHook(window)
        window.$RefreshReg$ = () => {}
        window.$RefreshSig$ = () => (type) => type
        window.__vite_plugin_react_preamble_installed__ = true
        </script>';
    }

    public function register_all_scripts() {
        $port = defined( 'YAYMATIK_VITE_PORT' ) ? \YAYMATIK_VITE_PORT : 3020;
        wp_register_script( ScriptName::PAGE_ADMIN, 'http://localhost:' . $port . '/main.tsx', ScriptName::ADMIN_DEPS, null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
    }
}
