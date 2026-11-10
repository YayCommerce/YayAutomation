<?php
namespace YayMatik\Workflow\Core\Storage;

use YayMatik\Utils\Logger;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** Rows of the `run_steps` table, one per step a run ran; dates in GMT, `input` and `output` as JSON. */
final class RunStepStore {

    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SKIPPED   = 'skipped';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_WAITING   = 'waiting';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
        self::STATUS_SKIPPED,
        self::STATUS_FAILED,
        self::STATUS_WAITING,
        self::STATUS_CANCELLED,
    ];

    /** Not finished yet. */
    public const OPEN = [ self::STATUS_RUNNING, self::STATUS_WAITING ];

    /**
     * A `running` row, written before the step runs, so one still `running` after a crash shows it was interrupted.
     *
     * @param int    $run_id
     * @param string $step_id Node id in the pinned graph.
     * @param array  $input   Config, merge tags unrendered.
     * @return int Row id; 0 when the insert failed (logged).
     */
    public static function start( int $run_id, string $step_id, array $input ): int {
        global $wpdb;
        $ok = $wpdb->insert(
            self::table(),
            [
                'run_id'     => $run_id,
                'step_id'    => $step_id,
                'status'     => self::STATUS_RUNNING,
                'input'      => wp_json_encode( $input ),
                'started_at' => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%s', '%s', '%s' ]
        );
        if ( ! $ok ) {
            Logger::error( 'Could not save a step: ' . $wpdb->last_error, [ 'run_id' => $run_id ] );
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * `waiting` leaves `finished_at` empty: the step finishes when the run resumes.
     *
     * @param int         $id
     * @param string      $status One of `STATUSES`.
     * @param array       $output
     * @param string|null $error
     */
    public static function finish( int $id, string $status, array $output = [], ?string $error = null ): bool {
        global $wpdb;
        if ( ! in_array( $status, self::STATUSES, true ) ) {
            return false;
        }
        $row = [
            'status' => $status,
            'output' => wp_json_encode( $output ),
            'error'  => $error,
        ];
        if ( ! in_array( $status, self::OPEN, true ) ) {
            $row['finished_at'] = current_time( 'mysql', true );
        }
        if ( false === $wpdb->update( self::table(), $row, [ 'id' => $id ] ) ) {
            Logger::error( 'Could not save a step: ' . $wpdb->last_error, [ 'run_step_id' => $id ] );
            return false;
        }
        return true;
    }

    /**
     * Ends a run's unfinished rows (running, waiting) with the run's own end status and reason.
     *
     * @param int    $run_id
     * @param string $status One of `STATUSES`, not an open one.
     * @param string $error
     */
    public static function close_open( int $run_id, string $status, string $error ): bool {
        global $wpdb;
        if ( ! in_array( $status, array_diff( self::STATUSES, self::OPEN ), true ) ) {
            return false;
        }
        $done = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built to match the array.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Schema::in() gives one %s per status.
                'UPDATE %i SET status = %s, error = %s, finished_at = %s WHERE run_id = %d AND status IN (' . Schema::in( count( self::OPEN ), '%s' ) . ')',
                array_merge( [ self::table(), $status, $error, current_time( 'mysql', true ), $run_id ], self::OPEN )
            )
        );
        if ( false === $done ) {
            Logger::error( 'Could not close a run\'s steps: ' . $wpdb->last_error, [ 'run_id' => $run_id ] );
            return false;
        }
        return true;
    }

    /**
     * The waiting rows of these runs → cancelled, for those `RunStore::cancel_waiting()` cancelled.
     *
     * @param int[]  $run_ids
     * @param string $reason
     */
    public static function cancel_waiting( array $run_ids, string $reason ): void {
        global $wpdb;
        $ids  = array_map( 'intval', $run_ids );
        $done = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built to match the array.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Schema::in() gives one %d per run.
                'UPDATE %i s INNER JOIN %i r ON r.id = s.run_id SET s.status = %s, s.error = %s, s.finished_at = %s WHERE s.run_id IN (' . Schema::in( count( $ids ) ) . ') AND r.status = %s AND s.status = %s',
                array_merge( [ self::table(), RunStore::table(), self::STATUS_CANCELLED, $reason, current_time( 'mysql', true ) ], $ids, [ RunStore::STATUS_CANCELLED, self::STATUS_WAITING ] )
            )
        );
        if ( false === $done ) {
            Logger::error( 'Could not cancel waiting steps: ' . $wpdb->last_error, [ 'run_ids' => $run_ids ] );
        }
    }

    /**
     * A run's steps in the order they ran, `input` and `output` decoded.
     *
     * @param int $run_id
     * @return array[]
     */
    public static function for_run( int $run_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d ORDER BY id ASC', self::table(), $run_id ), ARRAY_A );
        return array_map( [ self::class, 'decode' ], (array) $rows );
    }

    /**
     * How many steps each run ran, in one grouped query.
     *
     * @param int[] $run_ids
     * @return int[] Run id => count; runs without steps are left out.
     */
    public static function counts( array $run_ids ): array {
        global $wpdb;
        $ids = array_filter( array_map( 'intval', $run_ids ) );
        if ( ! $ids ) {
            return [];
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Schema::in() gives one %d per run.
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT run_id, COUNT(*) AS steps FROM %i WHERE run_id IN (' . Schema::in( count( $ids ) ) . ') GROUP BY run_id', array_merge( [ self::table() ], array_values( $ids ) ) ), ARRAY_A );
        return array_map( 'intval', array_column( (array) $rows, 'steps', 'run_id' ) );
    }

    public static function table(): string {
        return Schema::table( Schema::RUN_STEPS );
    }

    private static function decode( array $row ): array {
        foreach ( [ 'id', 'run_id', 'iteration', 'attempt' ] as $key ) {
            $row[ $key ] = (int) $row[ $key ];
        }
        // JSON objects → arrays; null, empty or broken JSON → [].
        foreach ( [ 'input', 'output' ] as $key ) {
            $value       = null === $row[ $key ] ? null : json_decode( (string) $row[ $key ], true );
            $row[ $key ] = is_array( $value ) ? $value : [];
        }
        return $row;
    }
}
