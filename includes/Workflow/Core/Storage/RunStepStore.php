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

    public const STATUSES = [
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
        self::STATUS_SKIPPED,
        self::STATUS_FAILED,
        self::STATUS_WAITING,
    ];

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
        if ( ! in_array( $status, [ self::STATUS_RUNNING, self::STATUS_WAITING ], true ) ) {
            $row['finished_at'] = current_time( 'mysql', true );
        }
        if ( false === $wpdb->update( self::table(), $row, [ 'id' => $id ] ) ) {
            Logger::error( 'Could not save a step: ' . $wpdb->last_error, [ 'run_step_id' => $id ] );
            return false;
        }
        return true;
    }

    /**
     * A run's steps in the order they ran, `input` and `output` decoded.
     *
     * @param int $run_id
     * @return array[]
     */
    public static function for_run( int $run_id ): array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d ORDER BY id ASC", $run_id ), ARRAY_A );
        return array_map( [ self::class, 'decode' ], (array) $rows );
    }

    public static function table(): string {
        return Schema::table( Schema::RUN_STEPS );
    }

    /**
     * @param array $row
     */
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
