<?php
namespace YayMatik\Workflow\Core\Storage;

use YayMatik\Utils\Logger;

defined( 'ABSPATH' ) || exit;

/** "This workflow already started for this subject", kept for as long as the workflow exists. */
final class TriggerClaimStore {

    /**
     * @param int    $workflow_id
     * @param string $subject
     * @return bool True only for the first claim; a failed write counts as refused, so no run starts.
     */
    public static function claim( int $workflow_id, string $subject ): bool {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, a write; the unique key decides who wins.
        $done = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (workflow_id, subject, claimed_at) VALUES (%d, %s, %s)", $workflow_id, $subject, current_time( 'mysql', true ) ) );
        if ( false === $done ) {
            Logger::error( 'Could not claim a trigger subject: ' . $wpdb->last_error, [ 'workflow_id' => $workflow_id, 'subject' => $subject ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
        }
        return 1 === $done;
    }

    /**
     * @param int    $workflow_id
     * @param string $subject
     */
    public static function release( int $workflow_id, string $subject ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, a write.
        $done = $wpdb->delete(
            self::table(),
            [
                'workflow_id' => $workflow_id,
                'subject'     => $subject,
            ],
            [ '%d', '%s' ]
        );
        if ( false === $done ) {
            Logger::error( 'Could not release a trigger claim: ' . $wpdb->last_error, [ 'workflow_id' => $workflow_id, 'subject' => $subject ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
        }
    }

    /**
     * Claims of deleted workflows. Selected first, as MySQL has no LIMIT on a multi-table delete.
     *
     * @param int $limit
     * @return int How many went.
     */
    public static function delete_orphans( int $limit ): int {
        global $wpdb;
        $table     = self::table();
        $workflows = Schema::table( Schema::WORKFLOWS );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables, daily cleanup.
        $ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT c.id FROM {$table} c LEFT JOIN {$workflows} w ON w.id = c.workflow_id WHERE w.id IS NULL LIMIT %d", $limit ) ) );
        if ( ! $ids ) {
            return 0;
        }
        $list = implode( ',', $ids );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, ids cast to int above.
        $done = $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$list})" );
        if ( false === $done ) {
            Logger::error( 'Could not delete claims of deleted workflows: ' . $wpdb->last_error );
        }
        return (int) $done;
    }

    private static function table(): string {
        return Schema::table( Schema::TRIGGER_CLAIMS );
    }
}
