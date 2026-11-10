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
        // Options were deleted by SQL, so cached copies (alloptions) would bring them back.
        wp_cache_flush();
    }

    /**
     * The current site.
     *
     * @param bool $all
     */
    public static function site( $all ) {
        global $wpdb;
        $p = $wpdb->prefix;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uninstall: table names from $wpdb->prefix, no user input.
        if ( self::table_exists( "{$p}actionscheduler_actions" ) ) {
            $group = $wpdb->prepare( 'g.slug = %s', self::GROUP );
            $wpdb->query( "DELETE l FROM {$p}actionscheduler_logs l JOIN {$p}actionscheduler_actions a ON a.action_id = l.action_id JOIN {$p}actionscheduler_groups g ON g.group_id = a.group_id WHERE {$group}" );
            $wpdb->query( "DELETE a FROM {$p}actionscheduler_actions a JOIN {$p}actionscheduler_groups g ON g.group_id = a.group_id WHERE {$group}" );
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$p}actionscheduler_groups WHERE slug = %s", self::GROUP ) );
        }
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_yaymatik\\_%' OR option_name LIKE '\\_transient\\_timeout\\_yaymatik\\_%'" );
        if ( ! $all ) {
            return;
        }
        foreach ( self::TABLES as $name ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall drops the plugin's own tables.
            $wpdb->query( "DROP TABLE IF EXISTS {$p}yaymatik_{$name}" );
        }
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'yaymatik\\_%'" );
        // Coupons stay: they may be in a shopper's inbox or used on orders. Only YayMatik's meta on them goes.
        $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_yaymatik\\_%'" );
        if ( self::table_exists( "{$p}wc_orders_meta" ) ) {
            $wpdb->query( "DELETE FROM {$p}wc_orders_meta WHERE meta_key LIKE '\\_yaymatik\\_%'" );
        }
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
