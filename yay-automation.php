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

if ( function_exists( 'YayAutomation\\plugin_init' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/Fallback.php';
    add_action(
        'admin_init',
        function () {
            deactivate_plugins( plugin_basename( __FILE__ ) );
        }
    );
}

if ( ! defined( 'YAY_AUTOMATION_VERSION' ) ) {
    define( 'YAY_AUTOMATION_VERSION', '0.1.0' );
    define( 'YAY_AUTOMATION_FILE', __FILE__ );
    define( 'YAY_AUTOMATION_PLUGIN_URL', plugin_dir_url( YAY_AUTOMATION_FILE ) );
    define( 'YAY_AUTOMATION_PLUGIN_DIR', plugin_dir_path( YAY_AUTOMATION_FILE ) );
    define( 'YAY_AUTOMATION_BASE_NAME', plugin_basename( YAY_AUTOMATION_FILE ) );
    define( 'YAY_AUTOMATION_MENU_ORDER', 30 );
}

if ( ! function_exists( 'YayAutomation\\plugin_init' ) ) {
    // Composer: scoped admin-shell (vendor-prefixed/). Its classmap is authoritative, so
    // autoloader.php still resolves YayAutomation\ classes added after the last dump.
    require_once YAY_AUTOMATION_PLUGIN_DIR . 'vendor/autoload.php';
    function plugin_init() {
        \YayAutomationScoped\YayCommerce\AdminShell\AdminShell::boot();

        if ( ! function_exists( 'WC' ) ) {
            add_action(
                'admin_notices',
                function () {
                    /* translators: %s: Woocommerce link */
                    echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'YayAutomation is enabled but not effective. It requires %s in order to work', 'yay-automation' ), '<a href="' . esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ) . '">WooCommerce</a>' ) . '</strong></p></div>';
                }
            );
            return;
        }

        YayAutomation::initialize();
        I18n::get_instance();
    }
}//end if

if ( ! wp_installing() ) {
    add_action( 'plugins_loaded', 'YayAutomation\\plugin_init' );
}
