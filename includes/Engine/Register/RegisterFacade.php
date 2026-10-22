<?php
namespace YayMatik\Engine\Register;

use YayMatik\Utils\SingletonTrait;
use YayMatik\YayMatik;

/**
 * Registers the admin assets and loads the script as a module.
 *
 * @method static RegisterFacade get_instance()
 */
class RegisterFacade {
    use SingletonTrait;

    protected function __construct() {
        add_filter( 'script_loader_tag', [ $this, 'add_entry_as_module' ], 10, 3 );
        add_action( 'init', [ $this, 'register_all_assets' ] );

        // The release zip leaves out RegisterDev.php (dev/scripts/release.sh).
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

        // The handle holds `/`, so it is escaped before the regex looks for this handle's `<script>` tag.
        $handle_regex = str_replace( '/', '\/', $handle );
        $pattern      = '/<script[^>]*id="' . $handle_regex . '-js"[^>]*>.*?<\/script>/';
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
