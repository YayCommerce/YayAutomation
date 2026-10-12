<?php
namespace YayAutomation\Workflow\Storage;

use YayAutomation\Utils\Logger;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Rows of the `run_steps` table: one per step a run ran. A row is written as `running` before the
 * step runs and finished with its result, so a row still `running` after a crash shows the step was
 * interrupted. `input` is the step's config with merge tags unrendered; `output` its declared
 * outputs. Dates are GMT; `input` and `output` are stored as JSON.
 */
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
     * A row for a step about to run.
     *
     * @param int    $run_id  Run id.
     * @param string $step_id Step (node) id in the pinned graph.
     * @param array  $input   Step config, merge tags unrendered.
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
     * Stores a step's result. `waiting` leaves `finished_at` empty; the step finishes when the run resumes.
     *
     * @param int         $id     Row id.
     * @param string      $status One of `STATUSES`.
     * @param array       $output Declared outputs.
     * @param string|null $error  Reason for `skipped` / `failed`.
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
     * @param int $run_id Run id.
     * @return array[]
     */
    public static function for_run( int $run_id ): array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d ORDER BY id ASC", $run_id ), ARRAY_A );
        return array_map( [ self::class, 'decode' ], (array) $rows );
    }

    /**
     * The step left `running`, if any: after a crash, the one that was interrupted.
     *
     * @param int $run_id Run id.
     */
    public static function find_running( int $run_id ): ?array {
        global $wpdb;
        $table = self::table();
        $row   = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d AND status = %s ORDER BY id DESC LIMIT 1", $run_id, self::STATUS_RUNNING ),
            ARRAY_A
        );
        return $row ? self::decode( $row ) : null;
    }

    public static function table(): string {
        return Schema::table( Schema::RUN_STEPS );
    }

    /**
     * @param array $row Row as read.
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
