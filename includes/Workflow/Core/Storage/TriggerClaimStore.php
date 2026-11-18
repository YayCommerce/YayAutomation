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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, a write; the unique key decides who wins.
        $done = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (workflow_id, subject, claimed_at) VALUES (%d, %s, %s)', self::table(), $workflow_id, $subject, current_time( 'mysql', true ) ) );
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
     * A guest's claim key: a hash, so no plain email is kept, and short enough for the subject column.
     *
     * @param string $email
     */
    public static function email_key( string $email ): string {
        return 'email:' . hash( 'sha256', strtolower( trim( $email ) ) );
    }

    /**
     * The claims kept for this email, for a personal data export.
     *
     * @param string $email
     * @return array[] [ 'workflow_id', 'claimed_at' ]
     */
    public static function find_keys( string $email ): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, a privacy export.
        return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT workflow_id, claimed_at FROM %i WHERE subject = %s ORDER BY claimed_at', self::table(), self::email_key( $email ) ), ARRAY_A );
    }

    /**
     * Deletes the claims kept for this email.
     *
     * @param string $email
     * @return int|false How many went; false when the delete failed.
     */
    public static function delete_keys( string $email ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, a privacy erase.
        $done = $wpdb->delete( self::table(), [ 'subject' => self::email_key( $email ) ], [ '%s' ] );
        if ( false === $done ) {
            Logger::error( 'Could not delete the trigger claims of an erased email: ' . $wpdb->last_error );
        }
        return $done;
    }

    /**
     * Claims of deleted workflows. Selected first, as MySQL has no LIMIT on a multi-table delete.
     *
     * @param int $limit
     * @return int How many went.
     */
    public static function delete_orphans( int $limit ): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own tables, daily cleanup.
        $ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT c.id FROM %i c LEFT JOIN %i w ON w.id = c.workflow_id WHERE w.id IS NULL LIMIT %d', self::table(), Schema::table( Schema::WORKFLOWS ), $limit ) ) );
        if ( ! $ids ) {
            return 0;
        }
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- own table; Schema::in() gives one %d per id.
        $done = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . Schema::in( count( $ids ) ) . ')', array_merge( [ self::table() ], $ids ) ) );
        if ( false === $done ) {
            Logger::error( 'Could not delete claims of deleted workflows: ' . $wpdb->last_error );
        }
        return (int) $done;
    }

    private static function table(): string {
        return Schema::table( Schema::TRIGGER_CLAIMS );
    }
}
