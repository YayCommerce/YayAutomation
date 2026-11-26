<?php
/**
 * Plugin Name:       YayMatik - Automation for WooCommerce
 * Plugin URI:        https://yaycommerce.com/yaymatik
 * Description:       Build automation workflows on a visual canvas, or start from a template.
 * Version:           1.0.0
 * Author:            YayCommerce
 * Author URI:        https://yaycommerce.com
 * Text Domain:       yaymatik-automation-for-woocommerce
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

// WordPress loads plugins in folder order, and the free folder sorts before yaymatik-pro/: the free copy steps aside
// for an active YayMatik Pro whichever loads first. A suffix match: Pro under another folder name still counts. Like
// WordPress, an active entry whose file is gone (folder deleted without deactivating) doesn't count.
$yaymatik_pro_scope = '';
if ( 'yaymatik-pro.php' !== basename( __FILE__ ) ) {
    $yaymatik_pro_file = function ( $plugin ) {
        return '/yaymatik-pro.php' === substr( (string) $plugin, -17 ) && is_file( WP_PLUGIN_DIR . '/' . $plugin );
    };
    if ( is_multisite() && array_filter( array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ), $yaymatik_pro_file ) ) {
        $yaymatik_pro_scope = 'network';
    } elseif ( array_filter( (array) get_option( 'active_plugins', [] ), $yaymatik_pro_file ) ) {
        $yaymatik_pro_scope = 'site';
    }
    unset( $yaymatik_pro_file );
}

if ( $yaymatik_pro_scope || function_exists( 'YayMatik\\plugin_init' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/Fallback.php';
    // Beside a Pro active on one site only, a network-active free copy can't leave just that site (it stays unloaded
    // there): deactivating it network-wide would take it from every other site.
    $yaymatik_network_wide = 'site' === $yaymatik_pro_scope ? false : null;
    add_action(
        'admin_init',
        function () use ( $yaymatik_network_wide ) {
            deactivate_plugins( plugin_basename( __FILE__ ), false, $yaymatik_network_wide );
        }
    );
    unset( $yaymatik_network_wide );
}
if ( $yaymatik_pro_scope ) {
    unset( $yaymatik_pro_scope );
    return;
}
unset( $yaymatik_pro_scope );

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
