<?php
/**
 * Plugin Name:       YayMatik - Automation for WooCommerce
 * Plugin URI:        https://yaycommerce.com/yaymatik/
 * Description:       Build automation workflows on a visual canvas, or start from a template.
 * Version:           0.1.0
 * Author:            YayCommerce
 * Author URI:        https://yaycommerce.com
 * Text Domain:       yaymatik
 * Domain Path:       /languages
 * Requires at least: 6.5
 * Requires PHP:      7.4
 *
 * @package yaycommerce/yaymatik
 */

namespace YayMatik;

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'YayMatik\\plugin_init' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/Fallback.php';
    add_action(
        'admin_init',
        function () {
            deactivate_plugins( plugin_basename( __FILE__ ) );
        }
    );
}

if ( ! defined( 'YAYMATIK_VERSION' ) ) {
    define( 'YAYMATIK_VERSION', '0.1.0' );
    define( 'YAYMATIK_FILE', __FILE__ );
    define( 'YAYMATIK_PLUGIN_URL', plugin_dir_url( YAYMATIK_FILE ) );
    define( 'YAYMATIK_PLUGIN_DIR', plugin_dir_path( YAYMATIK_FILE ) );
    define( 'YAYMATIK_BASE_NAME', plugin_basename( YAYMATIK_FILE ) );
    define( 'YAYMATIK_MENU_ORDER', 30 );
}

if ( ! function_exists( 'YayMatik\\plugin_init' ) ) {
    // Composer: YayMatik\ (PSR-4, includes/) + scoped admin-shell (vendor-prefixed/).
    // Missing? Run ./run.sh setup.
    require_once YAYMATIK_PLUGIN_DIR . 'vendor/autoload.php';
    function plugin_init() {
        \YayMatikScoped\YayCommerce\AdminShell\AdminShell::boot();

        if ( ! function_exists( 'WC' ) ) {
            add_action(
                'admin_notices',
                function () {
                    /* translators: %s: Woocommerce link */
                    echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'YayMatik is enabled but not effective. It requires %s in order to work', 'yaymatik' ), '<a href="' . esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ) . '">WooCommerce</a>' ) . '</strong></p></div>';
                }
            );
            return;
        }

        YayMatik::initialize();
        I18n::get_instance();
    }
}//end if

if ( ! wp_installing() ) {
    add_action( 'plugins_loaded', 'YayMatik\\plugin_init' );
}
