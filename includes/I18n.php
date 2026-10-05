<?php

namespace YayAutomation;

use YayAutomation\Utils\SingletonTrait;

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
        unload_textdomain( 'yay-automation' );
        load_textdomain( 'yay-automation', YAY_AUTOMATION_PLUGIN_DIR . 'languages/yay-automation-' . $locale . '.mo' );
        load_plugin_textdomain( 'yay-automation', false, YAY_AUTOMATION_PLUGIN_DIR . 'languages/' );
    }
}
