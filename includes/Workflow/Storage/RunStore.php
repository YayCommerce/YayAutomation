<?php
namespace YayAutomation\Workflow\Storage;

use YayAutomation\Utils\Logger;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Rows of the `runs` table. A run pins the workflow version it started on; the executor loads that
 * version, never the live graph. Dates are GMT; `position` and `scope` are stored as JSON.
 */
final class RunStore {

    public const STATUS_QUEUED    = 'queued';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_WAITING   = 'waiting';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_SKIPPED   = 'skipped';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_WAITING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_SKIPPED,
    ];

    /** A run in one of these has ended; `finished_at` is set when it gets there. */
    public const FINISHED = [ self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_SKIPPED ];

    public const PER_PAGE = 20;

    /**
     * A queued run. A run with no parent is its own root.
     *
     * @param array $run `workflow_id`, `version_id`, `trigger_id`, `subject`, `scope` (array), and for
     *                   a run started by another run `parent_run_id`, `root_run_id`, `depth`.
     * @return int Run id; 0 when the insert failed (logged).
     */
    public static function create( array $run ): int {
        global $wpdb;
        $ok = $wpdb->insert(
            self::table(),
            [
                'workflow_id'   => (int) $run['workflow_id'],
                'version_id'    => (int) $run['version_id'],
                'trigger_id'    => (string) ( $run['trigger_id'] ?? '' ),
                'subject'       => (string) ( $run['subject'] ?? '' ),
                'status'        => self::STATUS_QUEUED,
                'scope'         => wp_json_encode( $run['scope'] ?? [] ),
                'parent_run_id' => (int) ( $run['parent_run_id'] ?? 0 ),
                'root_run_id'   => (int) ( $run['root_run_id'] ?? 0 ),
                'depth'         => (int) ( $run['depth'] ?? 0 ),
                'started_at'    => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ]
        );
        if ( ! $ok ) {
            Logger::error( 'Could not create a run: ' . $wpdb->last_error, [ 'workflow_id' => (int) $run['workflow_id'] ] );
            return 0;
        }
        $id = (int) $wpdb->insert_id;
        if ( empty( $run['root_run_id'] ) ) {
            $wpdb->update( self::table(), [ 'root_run_id' => $id ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        }
        return $id;
    }

    /**
     * A run with `position` and `scope` decoded, or null.
     *
     * @param int $id Run id.
     */
    public static function find( int $id ): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
        return $row ? self::decode( $row ) : null;
    }

    /**
     * Newest first. Returns up to `PER_PAGE` + 1 rows, so the caller can tell whether a next page
     * exists. Without `scope`, which can be large.
     *
     * @param array $args `workflow_id`, `status` (string or list), `page` (1-based). All optional.
     * @return array[]
     */
    public static function list( array $args = [] ): array {
        global $wpdb;
        $where  = [];
        $params = [];
        if ( ! empty( $args['workflow_id'] ) ) {
            $where[]  = 'workflow_id = %d';
            $params[] = (int) $args['workflow_id'];
        }
        $statuses = array_values( array_intersect( (array) ( $args['status'] ?? [] ), self::STATUSES ) );
        if ( $statuses ) {
            $where[] = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
            $params  = array_merge( $params, $statuses );
        }
        $params[] = self::PER_PAGE + 1;
        $params[] = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * self::PER_PAGE;

        $table   = self::table();
        $columns = 'id, workflow_id, version_id, trigger_id, subject, status, position, wait_until, error, parent_run_id, root_run_id, depth, started_at, finished_at';
        $filter  = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $rows    = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built to match the array.
            $wpdb->prepare( "SELECT {$columns} FROM {$table} {$filter} ORDER BY id DESC LIMIT %d OFFSET %d", $params ),
            ARRAY_A
        );
        return array_map( [ self::class, 'decode' ], $rows );
    }

    /**
     * Runs of a workflow started in the last `$seconds`, for the rate limits: `all`, and `chained`
     * (depth > 0: started by another run, as every loop is). One query.
     *
     * @param int $workflow_id Workflow id.
     * @param int $seconds     Window.
     * @return int[] `all`, `chained`.
     */
    public static function recent_counts( int $workflow_id, int $seconds ): array {
        global $wpdb;
        $table  = self::table();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $seconds );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, COALESCE(SUM(depth > 0), 0) AS chained FROM {$table} WHERE workflow_id = %d AND started_at >= %s", $workflow_id, $cutoff ), ARRAY_A );
        return [
            'all'     => (int) ( $row['total'] ?? 0 ),
            'chained' => (int) ( $row['chained'] ?? 0 ),
        ];
    }

    /**
     * Takes a run for one worker: moves it to `running` only if it is still in one of `$from`.
     * The single UPDATE is the row lock, so two workers never both get true. A `waiting` run is
     * only taken once its `wait_until` has passed; `wait_until` stays, so the Executor can tell a
     * woken wait from an interrupted one.
     *
     * Taking a run from `running` (recovery) first hands it back as `queued`: an UPDATE that leaves
     * a row as it was counts 0 rows in MySQL, so `running` → `running` would never succeed.
     *
     * @param int      $id   Run id.
     * @param string[] $from Statuses it may be taken from.
     */
    public static function claim( int $id, array $from = [ self::STATUS_QUEUED, self::STATUS_WAITING ] ): bool {
        global $wpdb;
        $from = array_values( array_intersect( $from, self::STATUSES ) );
        if ( ! $from ) {
            return false;
        }
        $table = self::table();
        if ( in_array( self::STATUS_RUNNING, $from, true ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE id = %d AND status = %s", self::STATUS_QUEUED, $id, self::STATUS_RUNNING ) );
            $from = array_values( array_unique( array_merge( array_diff( $from, [ self::STATUS_RUNNING ] ), [ self::STATUS_QUEUED ] ) ) );
        }
        $in   = implode( ',', array_fill( 0, count( $from ), '%s' ) );
        $done = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built to match the array.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                "UPDATE {$table} SET status = %s WHERE id = %d AND status IN ({$in}) AND ( status <> %s OR wait_until IS NULL OR wait_until <= %s )",
                array_merge( [ self::STATUS_RUNNING, $id ], $from, [ self::STATUS_WAITING, current_time( 'mysql', true ) ] )
            )
        );
        return 1 === $done;
    }

    /**
     * Saves where a run is. Only the given keys change: `position` and `scope` (arrays), `status`,
     * `wait_until` (GMT `Y-m-d H:i:s` or null), `error`. A finishing status sets `finished_at`.
     *
     * @param int   $id     Run id.
     * @param array $fields Keys above.
     */
    public static function save( int $id, array $fields ): bool {
        global $wpdb;
        $row = [];
        foreach ( [ 'position', 'scope' ] as $key ) {
            if ( array_key_exists( $key, $fields ) ) {
                $row[ $key ] = null === $fields[ $key ] ? null : wp_json_encode( $fields[ $key ] );
            }
        }
        foreach ( [ 'wait_until', 'error' ] as $key ) {
            if ( array_key_exists( $key, $fields ) ) {
                $row[ $key ] = null === $fields[ $key ] ? null : (string) $fields[ $key ];
            }
        }
        if ( isset( $fields['status'] ) ) {
            if ( ! in_array( $fields['status'], self::STATUSES, true ) ) {
                return false;
            }
            $row['status'] = $fields['status'];
            if ( in_array( $fields['status'], self::FINISHED, true ) ) {
                $row['finished_at'] = current_time( 'mysql', true );
            }
        }
        if ( ! $row ) {
            return true;
        }
        if ( false === $wpdb->update( self::table(), $row, [ 'id' => $id ] ) ) {
            Logger::error( 'Could not save a run: ' . $wpdb->last_error, [ 'run_id' => $id ] );
            return false;
        }
        return true;
    }

    public static function table(): string {
        return Schema::table( Schema::RUNS );
    }

    /**
     * @param array $row Row as read.
     */
    private static function decode( array $row ): array {
        foreach ( [ 'id', 'workflow_id', 'version_id', 'parent_run_id', 'root_run_id', 'depth' ] as $key ) {
            $row[ $key ] = (int) $row[ $key ];
        }
        $row['position'] = null === $row['position'] ? null : json_decode( $row['position'], true );
        if ( array_key_exists( 'scope', $row ) ) {
            $row['scope'] = null === $row['scope'] ? [] : (array) json_decode( $row['scope'], true );
        }
        return $row;
    }
}
