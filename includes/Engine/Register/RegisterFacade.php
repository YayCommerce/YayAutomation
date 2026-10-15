<?php
namespace YayMatik\Engine\Register;

use YayMatik\Utils\SingletonTrait;
use YayMatik\YayMatik;

/**
 * Register Facade.
 *
 * @method static RegisterFacade get_instance()
 */
class RegisterFacade {
    use SingletonTrait;

    /** Hooks Initialization */
    protected function __construct() {
        add_filter( 'script_loader_tag', [ $this, 'add_entry_as_module' ], 10, 3 );
        add_action( 'init', [ $this, 'register_all_assets' ] );

        $is_prod = YayMatik::is_prod();
        if ( $is_prod && class_exists( '\YayMatik\Engine\Register\RegisterProd' ) ) {
            \YayMatik\Engine\Register\RegisterProd::get_instance();
        } elseif ( ! $is_prod && class_exists( '\YayMatik\Engine\Register\RegisterDev' ) ) {
            \YayMatik\Engine\Register\RegisterDev::get_instance();
        }
    }

    public function add_entry_as_module( $tag, $handle ) {
        if ( strpos( $handle, ScriptName::MODULE_PREFIX ) === false ) {
            return $tag;
        }

        // $handle could has special characters yay-plugin/module/admin-page
        $handle_regex = str_replace( '/', '\/', $handle );
        // find the string begin with '<script' and end with '</script>' between having 'id="' . $handle . '-js"'
        $pattern = '/<script[^>]*id="' . $handle_regex . '-js"[^>]*>.*?<\/script>/';
        preg_match( $pattern, $tag, $matches );

        if ( null !== $matches && count( $matches ) > 0 ) {
            $script_tag = $matches[0];

            if ( strpos( $script_tag, 'type="' ) !== false ) {
                $module_tag = preg_replace( '/\stype="\S+\s/', ' type="module" ', $script_tag, -1 );
            } else {
                $module_tag = str_replace( ' src=', ' type="module" src=', $script_tag );
            }

            return str_replace( $script_tag, $module_tag, $tag );
        }

        return $tag;
    }

    public function register_all_assets() {
        wp_register_style(
            ScriptName::STYLE_ADMIN,
            YAYMATIK_PLUGIN_URL . 'assets/dist/admin-page/style.css',
            [],
            YAYMATIK_VERSION
        );
    }
}
