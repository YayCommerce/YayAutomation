<?php
namespace YayMatik\Workflow\Core\Storage;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Rows of the `trigger_claims` table: a workflow ran for a subject (`order:12`).
 * Stops scheduled and query triggers from starting the same workflow twice for one subject, or
 * within a window ("once per customer per 90 days"). Dates are GMT.
 */
final class ClaimStore {

    /**
     * Claims a subject for a workflow. True only for the one caller that gets it: on the first
     * claim, or when the last one is older than `$window` seconds. Each path is a single statement,
     * so two requests never both get true.
     *
     * @param int      $workflow_id Workflow id.
     * @param string   $subject     What the run is about.
     * @param int|null $window      Seconds before the subject can be claimed again; null = never.
     */
    public static function claim( int $workflow_id, string $subject, ?int $window = null ): bool {
        global $wpdb;
        $table = self::table();
        $now   = current_time( 'mysql', true );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $added = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (workflow_id, subject, claimed_at) VALUES (%d, %s, %s)", $workflow_id, $subject, $now ) );
        if ( 1 === $added ) {
            return true;
        }
        if ( null === $window ) {
            return false;
        }
        $cutoff  = gmdate( 'Y-m-d H:i:s', time() - $window );
        $renewed = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare( "UPDATE {$table} SET claimed_at = %s WHERE workflow_id = %d AND subject = %s AND claimed_at < %s", $now, $workflow_id, $subject, $cutoff )
        );
        return 1 === $renewed;
    }

    public static function table(): string {
        return Schema::table( Schema::TRIGGER_CLAIMS );
    }
}
