<?php
namespace YayMatik\Workflow\Core\Storage;

use YayMatik\Utils\Logger;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;

defined( 'ABSPATH' ) || exit;

/** The plugin's tables, `{prefix}yaymatik_<name>`, and their installer. Dates are GMT. */
final class Schema {

    // Bump when a table below changes; dbDelta() then upgrades the tables on the next load.
    private const DB_VERSION        = '3';
    private const DB_VERSION_OPTION = 'yaymatik_db_version';

    public const VERSIONS     = 'versions';
    public const RUNS         = 'runs';
    public const RUN_STEPS    = 'run_steps';
    public const UNSUBSCRIBES = 'unsubscribes';

    /**
     * @param string $name One of the table constants.
     */
    public static function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . 'yaymatik_' . $name;
    }

    /** Creates or upgrades the tables; runs on every load, so plugin updates are covered. */
    public static function maybe_install(): void {
        if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
            return;
        }
        UnsubscribeLink::create_secret();
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta( array_map( fn( string $columns ) => $columns . " {$charset};", self::tables() ) );
        // Without every table, keep the old version so the next load tries again.
        foreach ( [ self::VERSIONS, self::RUNS, self::RUN_STEPS, self::UNSUBSCRIBES ] as $name ) {
            $table = self::table( $name );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- install check, runs once per DB version.
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
                Logger::error( 'Install left a table missing: ' . $wpdb->last_error, [ 'table' => $table ] );
                return;
            }
        }
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /**
     * `CREATE TABLE` statements without the charset. dbDelta() needs two spaces after PRIMARY KEY;
     * `trigger` and `cursor` are MySQL reserved words, hence `trigger_id` and `position`.
     */
    private static function tables(): array {
        $versions = self::table( self::VERSIONS );
        $runs     = self::table( self::RUNS );
        $steps    = self::table( self::RUN_STEPS );
        $unsubs   = self::table( self::UNSUBSCRIBES );
        return [
            // A saved version never changes; only the draft row is edited in place.
            "CREATE TABLE {$versions} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                workflow_id bigint(20) unsigned NOT NULL,
                action varchar(20) NOT NULL,
                graph longtext NOT NULL,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY workflow_id (workflow_id)
            )",
            // One per workflow match. Pins the version it started on; `subject` is what it's about (`order:12`).
            "CREATE TABLE {$runs} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                workflow_id bigint(20) unsigned NOT NULL,
                version_id bigint(20) unsigned NOT NULL,
                trigger_id varchar(100) NOT NULL DEFAULT '',
                subject varchar(100) NOT NULL DEFAULT '',
                status varchar(20) NOT NULL,
                position text NULL,
                scope longtext NULL,
                wait_until datetime NULL,
                error text NULL,
                parent_run_id bigint(20) unsigned NOT NULL DEFAULT 0,
                root_run_id bigint(20) unsigned NOT NULL DEFAULT 0,
                depth smallint(5) unsigned NOT NULL DEFAULT 0,
                started_at datetime NOT NULL,
                finished_at datetime NULL,
                PRIMARY KEY  (id),
                KEY status (status),
                KEY workflow_id (workflow_id,started_at),
                KEY root_run_id (root_run_id),
                KEY wait_until (wait_until)
            )",
            "CREATE TABLE {$steps} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                run_id bigint(20) unsigned NOT NULL,
                step_id varchar(100) NOT NULL,
                iteration int(10) unsigned NOT NULL DEFAULT 0,
                status varchar(20) NOT NULL,
                input longtext NULL,
                output longtext NULL,
                error text NULL,
                attempt smallint(5) unsigned NOT NULL DEFAULT 1,
                started_at datetime NULL,
                finished_at datetime NULL,
                PRIMARY KEY  (id),
                KEY run_id (run_id)
            )",
            // Addresses that get no customer emails, lowercased. 190 chars keeps the key within utf8mb4 index limits.
            "CREATE TABLE {$unsubs} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                email varchar(190) NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY email (email)
            )",
        ];
    }
}
