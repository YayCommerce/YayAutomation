<?php
namespace YayMatik;

defined( 'ABSPATH' ) || exit;

// uninstall.php requires this file without the autoloader; it runs SQL only, so it works when WooCommerce is already inactive.

/** Removes YayMatik's jobs and transients on uninstall, and all its data when `YAYMATIK_REMOVE_ALL_DATA` is true. */
final class Uninstaller {

    /** Schema's tables, without `{prefix}yaymatik_`; UninstallerTest keeps the list equal to Schema's constants. */
    const TABLES = [ 'workflows', 'workflow_versions', 'runs', 'run_steps', 'unsubscribes', 'carts', 'trigger_claims' ];

    /** Action Scheduler group of every YayMatik job. */
    const GROUP = 'yaymatik';

    /** Each site of a network, or this site. */
    public static function uninstall() {
        $all = defined( 'YAYMATIK_REMOVE_ALL_DATA' ) && YAYMATIK_REMOVE_ALL_DATA;
        if ( ! is_multisite() ) {
            self::site( $all );
        } else {
            foreach ( get_sites(
                [
                    'fields' => 'ids',
                    'number' => 0,
                ]
            ) as $site_id ) {
                switch_to_blog( $site_id );
                self::site( $all );
                restore_current_blog();
            }
        }
    }

    /**
     * The current site.
     *
     * @param bool $all
     */
    public static function site( $all ) {
        global $wpdb;
        $p = $wpdb->prefix;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- uninstall.
        if ( self::table_exists( $p . 'actionscheduler_actions' ) ) {
            $wpdb->query( $wpdb->prepare( 'DELETE l FROM %i l JOIN %i a ON a.action_id = l.action_id JOIN %i g ON g.group_id = a.group_id WHERE g.slug = %s', $p . 'actionscheduler_logs', $p . 'actionscheduler_actions', $p . 'actionscheduler_groups', self::GROUP ) );
            $wpdb->query( $wpdb->prepare( 'DELETE a FROM %i a JOIN %i g ON g.group_id = a.group_id WHERE g.slug = %s', $p . 'actionscheduler_actions', $p . 'actionscheduler_groups', self::GROUP ) );
            $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE slug = %s', $p . 'actionscheduler_groups', self::GROUP ) );
        }
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_yaymatik_' ) . '%', $wpdb->esc_like( '_transient_timeout_yaymatik_' ) . '%' ) );
        if ( $all ) {
            foreach ( self::TABLES as $name ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall drops the plugin's own tables.
                $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $p . 'yaymatik_' . $name ) );
            }
            $options = $wpdb->esc_like( 'yaymatik_' ) . '%';
            // Options not autoloaded are cached one by one, by name.
            foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $options ) ) as $option ) {
                wp_cache_delete( $option, 'options' );
            }
            $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $options ) );
            // Coupons stay: they may be in a shopper's inbox or used on orders. Only YayMatik's meta on them goes.
            $meta = $wpdb->esc_like( '_yaymatik_' ) . '%';
            $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->postmeta, $meta ) );
            if ( self::table_exists( $p . 'wc_orders_meta' ) ) {
                $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $p . 'wc_orders_meta', $meta ) );
            }
        }
        // Options went by SQL: the cached option lists would bring them back on this request.
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        // phpcs:enable
    }

    /**
     * @param string $table
     */
    private static function table_exists( $table ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    }
}
