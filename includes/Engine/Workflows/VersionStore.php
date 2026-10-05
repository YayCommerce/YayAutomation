<?php
namespace YayAutomation\Engine\Workflows;

use YayAutomation\Utils\Logger;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Rows of the `yay_automation_versions` table: one snapshot of a workflow's graph per version, with
 * the last action taken on it. Which row is live or the draft is tracked by `WorkflowRepository`.
 * Dates are GMT.
 */
final class VersionStore {

    public const ACTION_CREATED     = 'created';
    public const ACTION_EDITED      = 'edited';
    public const ACTION_ACTIVATED   = 'activated';
    public const ACTION_DEACTIVATED = 'deactivated';

    public const PER_PAGE = 10;

    // Bump when the schema below changes; dbDelta() then upgrades the table on the next load.
    private const DB_VERSION        = '1';
    private const DB_VERSION_OPTION = 'yay_automation_db_version';

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'yay_automation_versions';
    }

    /** Creates or upgrades the table. Runs on every load, so it also covers plugin updates. */
    public static function maybe_install(): void {
        if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        // dbDelta() needs two spaces after PRIMARY KEY.
        dbDelta(
            "CREATE TABLE {$table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                workflow_id bigint(20) unsigned NOT NULL,
                action varchar(20) NOT NULL,
                graph longtext NOT NULL,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY workflow_id (workflow_id)
            ) {$charset};"
        );
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /**
     * @param int         $workflow_id Workflow post id.
     * @param string      $action      One of the ACTION_ constants.
     * @param array       $graph       Graph snapshot.
     * @param int|null    $user_id     Null → the current user.
     * @param string|null $time        GMT `Y-m-d H:i:s`; null → now.
     * @return int Row id; 0 when the insert failed (logged).
     */
    public static function insert( int $workflow_id, string $action, array $graph, ?int $user_id = null, ?string $time = null ): int {
        global $wpdb;
        $time = $time ?? current_time( 'mysql', true );
        $ok   = $wpdb->insert(
            self::table(),
            [
                'workflow_id' => $workflow_id,
                'action'      => $action,
                'graph'       => wp_json_encode( $graph ),
                'user_id'     => $user_id ?? get_current_user_id(),
                'created_at'  => $time,
                'updated_at'  => $time,
            ],
            [ '%d', '%s', '%s', '%d', '%s', '%s' ]
        );
        if ( ! $ok ) {
            Logger::error( 'Could not record a workflow version: ' . $wpdb->last_error, [ 'workflow_id' => $workflow_id ] );
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Replaces a row's snapshot; the current user and now become its author and time.
     *
     * @param int    $id     Row id.
     * @param string $action One of the ACTION_ constants.
     * @param array  $graph  Graph snapshot.
     */
    public static function update( int $id, string $action, array $graph ): void {
        self::write(
            $id,
            [
                'action'  => $action,
                'graph'   => wp_json_encode( $graph ),
                'user_id' => get_current_user_id(),
            ]
        );
    }

    /**
     * Moves a row's time to now, nothing else.
     *
     * @param int $id Row id.
     */
    public static function touch( int $id ): void {
        self::write( $id, [] );
    }

    public static function delete( int $id ): void {
        global $wpdb;
        $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
    }

    public static function delete_for_workflow( int $workflow_id ): void {
        global $wpdb;
        $wpdb->delete( self::table(), [ 'workflow_id' => $workflow_id ], [ '%d' ] );
    }

    /**
     * Newest change first (a draft edited in place moves up). Returns up to PER_PAGE + 1 rows, so the caller can tell whether a next page exists.
     *
     * @param int $workflow_id Workflow post id.
     * @param int $page        1-based.
     * @return array[] Rows with `graph` still JSON.
     */
    public static function page( int $workflow_id, int $page ): array {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT id, action, graph, user_id, updated_at FROM {$table} WHERE workflow_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d",
                $workflow_id,
                self::PER_PAGE + 1,
                ( max( 1, $page ) - 1 ) * self::PER_PAGE
            ),
            ARRAY_A
        );
    }

    /**
     * Each workflow's newest row time, ISO 8601. Workflows without rows are left out.
     *
     * @param int[] $workflow_ids Workflow post ids.
     * @return array<int, string>
     */
    public static function last_updated( array $workflow_ids ): array {
        global $wpdb;
        $workflow_ids = array_map( 'intval', $workflow_ids );
        if ( ! $workflow_ids ) {
            return [];
        }
        $table = self::table();
        $in    = implode( ',', array_fill( 0, count( $workflow_ids ), '%d' ) );
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                "SELECT workflow_id, MAX(updated_at) AS updated_at FROM {$table} WHERE workflow_id IN ({$in}) GROUP BY workflow_id",
                $workflow_ids
            ),
            ARRAY_A
        );
        $map = [];
        foreach ( $rows as $row ) {
            $map[ (int) $row['workflow_id'] ] = self::to_iso( $row['updated_at'] );
        }
        return $map;
    }

    /**
     * @param string $gmt GMT `Y-m-d H:i:s`.
     */
    public static function to_iso( string $gmt ): string {
        return ( new \DateTimeImmutable( $gmt, new \DateTimeZone( 'UTC' ) ) )->format( 'c' );
    }

    private static function write( int $id, array $fields ): void {
        global $wpdb;
        $fields['updated_at'] = current_time( 'mysql', true );
        if ( false === $wpdb->update( self::table(), $fields, [ 'id' => $id ] ) ) {
            Logger::error( 'Could not update a workflow version: ' . $wpdb->last_error, [ 'version_id' => $id ] );
        }
    }
}
