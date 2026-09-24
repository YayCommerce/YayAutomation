<?php
/**
 * Plugin Name:       YayAutomation
 * Plugin URI:        https://yaycommerce.com/yay-automation/
 * Description:       Build automation workflows on a visual canvas, or start from a template.
 * Version:           0.1.0
 * Author:            YayCommerce
 * Author URI:        https://yaycommerce.com
 * Text Domain:       yay-automation
 * Domain Path:       /languages
 * Requires at least: 6.5
 * Requires PHP:      7.4
 *
 * @package yaycommerce/yay-automation
 */

namespace YayAutomation;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'YAY_AUTOMATION_VERSION' ) ) {
    define( 'YAY_AUTOMATION_VERSION', '0.1.0' );
}
if ( ! defined( 'YAY_AUTOMATION_FILE' ) ) {
    define( 'YAY_AUTOMATION_FILE', __FILE__ );
}
if ( ! defined( 'YAY_AUTOMATION_PLUGIN_URL' ) ) {
    define( 'YAY_AUTOMATION_PLUGIN_URL', plugin_dir_url( YAY_AUTOMATION_FILE ) );
}
if ( ! defined( 'YAY_AUTOMATION_PLUGIN_DIR' ) ) {
    define( 'YAY_AUTOMATION_PLUGIN_DIR', plugin_dir_path( YAY_AUTOMATION_FILE ) );
}
if ( ! defined( 'YAY_AUTOMATION_BASE_NAME' ) ) {
    define( 'YAY_AUTOMATION_BASE_NAME', plugin_basename( YAY_AUTOMATION_FILE ) );
}

require_once __DIR__ . '/autoloader.php';

if ( ! function_exists( 'YayAutomation\\init' ) ) {
    function init() {
        \YayAutomation\YayAutomation::initialize();
        \YayAutomation\I18n::get_instance();
    }
}
add_action( 'plugins_loaded', 'YayAutomation\\init' );
