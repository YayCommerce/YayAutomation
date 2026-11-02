<?php
namespace YayMatik\Workflow\Core\Storage;

use YayMatik\Utils\Logger;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;

defined( 'ABSPATH' ) || exit;

/** The plugin's tables, `{prefix}yaymatik_<name>`, and their installer. Dates are GMT. */
final class Schema {

    // Bump when a table below changes; dbDelta() then upgrades the tables on the next load.
    private const DB_VERSION        = '6';
    private const DB_VERSION_OPTION = 'yaymatik_db_version';

    public const WORKFLOWS         = 'workflows';
    public const WORKFLOW_VERSIONS = 'workflow_versions';
    public const RUNS              = 'runs';
    public const RUN_STEPS         = 'run_steps';
    public const UNSUBSCRIBES      = 'unsubscribes';
    public const CARTS             = 'carts';

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
        foreach ( [ self::WORKFLOWS, self::WORKFLOW_VERSIONS, self::RUNS, self::RUN_STEPS, self::UNSUBSCRIBES, self::CARTS ] as $name ) {
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
        $workflows = self::table( self::WORKFLOWS );
        $versions  = self::table( self::WORKFLOW_VERSIONS );
        $runs      = self::table( self::RUNS );
        $steps     = self::table( self::RUN_STEPS );
        $unsubs    = self::table( self::UNSUBSCRIBES );
        $carts     = self::table( self::CARTS );
        return [
            // `trigger_id` is the live graph's trigger. A draft exists only once turned on (`activated_at`
            // set); never turned on, edits go to the live row in place.
            "CREATE TABLE {$workflows} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                name text NOT NULL,
                status varchar(20) NOT NULL,
                trigger_id varchar(100) NOT NULL DEFAULT '',
                live_version_id bigint(20) unsigned NOT NULL DEFAULT 0,
                draft_version_id bigint(20) unsigned NOT NULL DEFAULT 0,
                activated_at datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY status (status,trigger_id)
            )",
            // A version's logic (`graph`) never changes, only the draft row's; its `layout` may, as moving a step isn't an edit.
            "CREATE TABLE {$versions} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                workflow_id bigint(20) unsigned NOT NULL,
                action varchar(20) NOT NULL,
                graph longtext NOT NULL,
                layout longtext NULL,
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
            // One per WooCommerce session (`session_key` = WC customer id: a user id, or `t_…` for a guest).
            // `items` is a JSON list of lines; `total` and line prices as the cart showed them.
            // `abandoned_at` and `abandon_trigger` hold a cycle's first abandon, kept when the shopper returns.
            "CREATE TABLE {$carts} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                session_key varchar(100) NOT NULL,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                email varchar(190) NOT NULL DEFAULT '',
                first_name varchar(190) NOT NULL DEFAULT '',
                items longtext NOT NULL,
                total decimal(19,4) NOT NULL DEFAULT 0,
                currency char(3) NOT NULL DEFAULT '',
                status varchar(20) NOT NULL,
                abandoned_at datetime NULL,
                abandon_trigger varchar(30) NOT NULL DEFAULT '',
                series_at datetime NULL,
                checkout_at datetime NULL,
                order_id bigint(20) unsigned NOT NULL DEFAULT 0,
                restored_at datetime NULL,
                last_activity_at datetime NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY session_key (session_key),
                KEY email (email),
                KEY status (status,last_activity_at),
                KEY order_id (order_id)
            )",
        ];
    }
}
