<?php

namespace YayMatik;

use YayMatik\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * I18n Logic
 */
class I18n {

    use SingletonTrait;

    private function __construct() {
        add_action( 'init', [ $this, 'load_plugin_textdomain' ] );
    }

    public function load_plugin_textdomain() {
        if ( function_exists( 'determine_locale' ) ) {
            $locale = determine_locale();
        } else {
            $locale = is_admin() ? get_user_locale() : get_locale();
        }
        unload_textdomain( 'yaymatik' );
        load_textdomain( 'yaymatik', YAYMATIK_PLUGIN_DIR . 'languages/yaymatik-' . $locale . '.mo' );
        load_plugin_textdomain( 'yaymatik', false, YAYMATIK_PLUGIN_DIR . 'languages/' );
    }
}
