<?php
/**
 * Plugin Name:       YayMatik - Automation for WooCommerce
 * Plugin URI:        https://yaycommerce.com/yaymatik/
 * Description:       Build automation workflows on a visual canvas, or start from a template.
 * Version:           1.0.0
 * Author:            YayCommerce
 * Author URI:        https://yaycommerce.com
 * Text Domain:       yaymatik
 * Domain Path:       /languages
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 11.0
 * WC tested up to:   11.3
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
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
    define( 'YAYMATIK_VERSION', '1.0.0' );
    define( 'YAYMATIK_FILE', __FILE__ );
    define( 'YAYMATIK_PLUGIN_URL', plugin_dir_url( YAYMATIK_FILE ) );
    define( 'YAYMATIK_PLUGIN_DIR', plugin_dir_path( YAYMATIK_FILE ) );
    define( 'YAYMATIK_BASE_NAME', plugin_basename( YAYMATIK_FILE ) );
    define( 'YAYMATIK_MENU_ORDER', 30 );
}

if ( ! function_exists( 'YayMatik\\plugin_init' ) ) {
    require_once YAYMATIK_PLUGIN_DIR . 'includes/Requirements.php';

    function plugin_init() {
        // The WooCommerce version is known only once plugins load, so the check, and the autoloader after it, wait until now.
        if ( ! Requirements::met() ) {
            return;
        }
        // Composer: YayMatik\ (PSR-4, includes/) + scoped admin-shell (vendor-prefixed/).
        // Missing? Run ./run.sh setup.
        require_once YAYMATIK_PLUGIN_DIR . 'vendor/autoload.php';
        \YayMatikScoped\YayCommerce\AdminShell\AdminShell::boot();
        YayMatik::initialize();
    }

    // Loads no class: it also runs on a site where the requirements failed and the autoloader never loaded.
    function deactivate( $network_wide = false ) {
        if ( ! $network_wide || ! is_multisite() ) {
            deactivate_site();
            return;
        }
        foreach ( get_sites(
            [
                'fields' => 'ids',
                'number' => 0,
            ]
        ) as $site_id ) {
            switch_to_blog( $site_id );
            deactivate_site();
            restore_current_blog();
        }
    }

    // The hooks, the group and the option are Cleanup's, CartSweep's and InactiveSweep's (CleanupTest keeps them equal).
    function deactivate_site() {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            foreach ( [ 'yaymatik_cleanup', 'yaymatik_cart_sweep', 'yaymatik_inactive_sweep' ] as $hook ) {
                as_unschedule_all_actions( $hook, [], 'yaymatik' );
            }
        }
        delete_option( 'yaymatik_cleanup_checked_at' );
    }
    register_deactivation_hook( __FILE__, 'YayMatik\\deactivate' );
}

if ( ! wp_installing() ) {
    add_action( 'plugins_loaded', 'YayMatik\\plugin_init' );
}
