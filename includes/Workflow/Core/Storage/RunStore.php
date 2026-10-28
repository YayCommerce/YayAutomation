<?php
namespace YayMatik\Workflow\Core\Storage;

use YayMatik\Utils\Logger;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** Rows of the `runs` table; dates in GMT, `position` and `scope` as JSON. */
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

    /** Ended: reaching one sets `finished_at`. */
    public const FINISHED = [ self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_SKIPPED ];

    public const PER_PAGE = 20;

    /** A worker holds the run's scope in memory; writing to it now would be undone. */
    private const IN_PROGRESS = [ self::STATUS_QUEUED, self::STATUS_RUNNING ];

    /**
     * A queued run; one with no parent is its own root.
     *
     * @param array $run `workflow_id`, `version_id`, `trigger_id`, `subject`, `scope` (array), and when
     *                   another run started it `parent_run_id`, `root_run_id`, `depth`.
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
     * @param int $id
     */
    public static function find( int $id ): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
        return $row ? self::decode( $row ) : null;
    }

    /**
     * One page, newest first, without `scope` (it can be large).
     *
     * @param array $args Optional `workflow_id`, `status` (string or list), `page` (1-based).
     * @return array[]
     */
    public static function list( array $args = [] ): array {
        global $wpdb;
        list( $filter, $params ) = self::filter( $args );
        $params[]                = self::PER_PAGE;
        $params[]                = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * self::PER_PAGE;

        $table   = self::table();
        $columns = 'id, workflow_id, version_id, trigger_id, subject, status, position, wait_until, error, parent_run_id, root_run_id, depth, started_at, finished_at';
        $rows    = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built to match the array.
            $wpdb->prepare( "SELECT {$columns} FROM {$table} {$filter} ORDER BY id DESC LIMIT %d OFFSET %d", $params ),
            ARRAY_A
        );
        return array_map( [ self::class, 'decode' ], (array) $rows );
    }

    /**
     * How many runs `list()` pages through.
     *
     * @param array $args Optional `workflow_id`, `status` (string or list).
     */
    public static function count( array $args = [] ): int {
        global $wpdb;
        list( $filter, $params ) = self::filter( $args );
        $table                   = self::table();
        $sql                     = "SELECT COUNT(*) FROM {$table} {$filter}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built to match the array; no filter, no placeholders.
        return (int) $wpdb->get_var( $params ? $wpdb->prepare( $sql, $params ) : $sql );
    }

    /**
     * Per workflow, in one query: runs started in the last 30 days, waiting runs, and the newest run by id.
     *
     * @param int[] $workflow_ids
     * @return array[] Workflow id => `recent`, `waiting`, `last_id`, `last_run` (GMT), `last_status`; workflows
     *                 without runs are left out.
     */
    public static function stats( array $workflow_ids ): array {
        global $wpdb;
        $ids = array_filter( array_map( 'intval', $workflow_ids ) );
        if ( ! $ids ) {
            return [];
        }
        $table = self::table();
        $in    = implode( ',', $ids );
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of ints.
                "SELECT s.workflow_id, s.recent, s.waiting, s.last_id, r.started_at AS last_run, r.status AS last_status FROM (SELECT workflow_id, SUM(started_at >= %s) AS recent, SUM(status = %s) AS waiting, MAX(id) AS last_id FROM {$table} WHERE workflow_id IN ({$in}) GROUP BY workflow_id) s INNER JOIN {$table} r ON r.id = s.last_id",
                gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 ),
                self::STATUS_WAITING
            ),
            ARRAY_A
        );
        $stats = [];
        foreach ( (array) $rows as $row ) {
            $stats[ (int) $row['workflow_id'] ] = [
                'recent'      => (int) $row['recent'],
                'waiting'     => (int) $row['waiting'],
                'last_id'     => (int) $row['last_id'],
                'last_run'    => $row['last_run'],
                'last_status' => (string) $row['last_status'],
            ];
        }
        return $stats;
    }

    /**
     * `WHERE …` and its params for `list()` and `count()`.
     *
     * @param array $args
     * @return array [ string $sql, array $params ]
     */
    private static function filter( array $args ): array {
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
        return [ $where ? 'WHERE ' . implode( ' AND ', $where ) : '', $params ];
    }

    /**
     * Runs of a workflow started in the last `$seconds`.
     *
     * @param int $workflow_id
     * @param int $seconds
     * @return int[] `all`, `chained` (depth > 0).
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
     * Moves a run to `running` if it is still in one of `$from`; the single UPDATE is the row lock.
     *
     * @param int      $id
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
            // Back to `queued` first: an UPDATE that leaves a row as it was counts 0 rows in MySQL.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE id = %d AND status = %s", self::STATUS_QUEUED, $id, self::STATUS_RUNNING ) );
            $from = array_values( array_unique( array_merge( array_diff( $from, [ self::STATUS_RUNNING ] ), [ self::STATUS_QUEUED ] ) ) );
        }
        // A waiting run only once its wait is over; `wait_until` stays, so the Executor can tell a woken
        // wait from an interrupted one.
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
     * Ids of a workflow's waiting runs.
     *
     * @param int $workflow_id
     * @return int[]
     */
    public static function waiting_ids( int $workflow_id ): array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE workflow_id = %d AND status = %s", $workflow_id, self::STATUS_WAITING ) );
        return array_map( 'intval', (array) $ids );
    }

    /**
     * Ends these runs as cancelled if still waiting, in one UPDATE, so a run its job took meanwhile is left alone.
     *
     * @param int[]  $ids
     * @param string $reason
     * @return int How many runs were cancelled.
     */
    public static function cancel_waiting( array $ids, string $reason ): int {
        global $wpdb;
        if ( ! $ids ) {
            return 0;
        }
        $table = self::table();
        $in    = implode( ',', array_map( 'intval', $ids ) );
        $done  = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of ints.
                "UPDATE {$table} SET status = %s, error = %s, wait_until = NULL, finished_at = %s WHERE id IN ({$in}) AND status = %s",
                self::STATUS_CANCELLED,
                $reason,
                current_time( 'mysql', true ),
                self::STATUS_WAITING
            )
        );
        if ( false === $done ) {
            Logger::error( 'Could not cancel waiting runs: ' . $wpdb->last_error, [ 'run_ids' => $ids ] );
            return 0;
        }
        return (int) $done;
    }

    /**
     * Saves only the given fields; a finishing status sets `finished_at`.
     *
     * @param int   $id
     * @param array $fields `position`, `scope` (arrays), `status`, `wait_until` (GMT `Y-m-d H:i:s` or null), `error`.
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

    /**
     * Finished runs, deleted workflows' included, that finished before the cutoff; oldest ids first.
     *
     * @param string $before GMT datetime.
     * @param int    $limit
     * @return int[]
     */
    public static function finished_before( string $before, int $limit ): array {
        global $wpdb;
        $table    = self::table();
        $open     = array_diff( self::STATUSES, self::FINISHED );
        $finished = implode( ',', array_fill( 0, count( self::FINISHED ), '%s' ) );
        $unended  = implode( ',', array_fill( 0, count( $open ), '%s' ) );
        // A chain with an open run keeps all its runs: the loop guard walks parent_run_id and stops at a missing row.
        $ids = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built to match the array.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                "SELECT r.id FROM {$table} r WHERE r.status IN ({$finished}) AND r.finished_at < %s AND NOT EXISTS (SELECT 1 FROM {$table} o WHERE o.root_run_id = r.root_run_id AND o.status IN ({$unended})) ORDER BY r.id LIMIT %d",
                array_merge( self::FINISHED, [ $before ], array_values( $open ), [ $limit ] )
            )
        );
        return array_map( 'intval', (array) $ids );
    }

    /**
     * Steps first, so a failure in between leaves no steps without their run.
     *
     * @param int[] $ids
     */
    public static function delete_with_steps( array $ids ): bool {
        global $wpdb;
        $in = implode( ',', array_map( 'intval', $ids ) );
        if ( '' === $in ) {
            return true;
        }
        $steps = RunStepStore::table();
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of ints.
        if ( false === $wpdb->query( "DELETE FROM {$steps} WHERE run_id IN ({$in})" ) || false === $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" ) ) {
            Logger::error( 'Could not delete old runs: ' . $wpdb->last_error );
            return false;
        }
        return true;
    }

    /**
     * Runs about a person (`about()`), any status; `workflow_name` null once deleted.
     *
     * @param string   $email Normalized.
     * @param string[] $subjects Run subjects of the person's orders and carts.
     * @param int      $limit
     * @param int      $offset
     * @return array[] `id`, `status`, `started_at`, `workflow_name`.
     */
    public static function for_export( string $email, array $subjects, int $limit, int $offset ): array {
        global $wpdb;
        $table                  = self::table();
        $workflows              = Schema::table( Schema::WORKFLOWS );
        list( $about, $params ) = self::about( $email, $subjects, false );
        $rows                   = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built to match the array.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                "SELECT r.id, r.status, r.started_at, w.name AS workflow_name FROM {$table} r LEFT JOIN {$workflows} w ON w.id = r.workflow_id WHERE {$about} ORDER BY r.id LIMIT %d OFFSET %d",
                array_merge( $params, [ $limit, $offset ] )
            ),
            ARRAY_A
        );
        return (array) $rows;
    }

    /**
     * The first runs erase changes: those mentioning the email, and waiting runs about the person's subjects;
     * queued and running ones left out.
     *
     * @param string   $email Normalized.
     * @param string[] $subjects
     * @param int      $limit
     * @return string[] Run id => status.
     */
    public static function ids_mentioning( string $email, array $subjects, int $limit ): array {
        global $wpdb;
        $table                  = self::table();
        $busy                   = implode( ',', array_fill( 0, count( self::IN_PROGRESS ), '%s' ) );
        list( $about, $params ) = self::about( $email, $subjects, true );
        $rows                   = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built to match the array.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                "SELECT r.id, r.status FROM {$table} r WHERE r.status NOT IN ({$busy}) AND ({$about}) ORDER BY r.id LIMIT %d",
                array_merge( self::IN_PROGRESS, $params, [ $limit ] )
            ),
            ARRAY_A
        );
        return array_column( (array) $rows, 'status', 'id' );
    }

    /**
     * Whether a queued or running run is about the person.
     *
     * @param string   $email Normalized.
     * @param string[] $subjects
     */
    public static function in_progress_mentioning( string $email, array $subjects ): bool {
        global $wpdb;
        $table                  = self::table();
        $busy                   = implode( ',', array_fill( 0, count( self::IN_PROGRESS ), '%s' ) );
        list( $about, $params ) = self::about( $email, $subjects, false );
        $found                  = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built to match the array.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                "SELECT r.id FROM {$table} r WHERE r.status IN ({$busy}) AND ({$about}) LIMIT 1",
                array_merge( self::IN_PROGRESS, $params )
            )
        );
        return null !== $found;
    }

    /**
     * `WHERE` part over `r` (runs): the email in any of the five columns `anonymize()` changes, or a run about one
     * of the subjects (runs started by a trigger hold refs, not the email).
     *
     * @param string   $email
     * @param string[] $subjects
     * @param bool     $waiting_subjects Subjects match waiting runs only: a finished run about them has nothing to erase.
     * @return array [ string $sql, array $params ]
     */
    private static function about( string $email, array $subjects, bool $waiting_subjects ): array {
        $steps  = RunStepStore::table();
        $like   = self::json_like( $email );
        $regexp = self::text_regexp( $email );
        $sql    = "r.scope LIKE %s OR r.error REGEXP %s OR EXISTS (SELECT 1 FROM {$steps} s WHERE s.run_id = r.id AND (s.input LIKE %s OR s.output LIKE %s OR s.error REGEXP %s))";
        $params = [ $like, $regexp, $like, $like, $regexp ];
        if ( $subjects ) {
            $sql   .= ' OR (r.subject IN (' . implode( ',', array_fill( 0, count( $subjects ), '%s' ) ) . ')' . ( $waiting_subjects ? ' AND r.status = %s' : '' ) . ')';
            $params = array_merge( $params, array_values( $subjects ), $waiting_subjects ? [ self::STATUS_WAITING ] : [] );
        }
        return [ $sql, $params ];
    }

    /**
     * The email → `[deleted]` in runs' scope and error, steps' input, output and error; statuses and dates stay.
     *
     * @param int[]  $ids Queued and running ones are left alone.
     * @param string $email Normalized.
     * @return int Runs with a row changed.
     */
    public static function anonymize( array $ids, string $email ): int {
        global $wpdb;
        $in = implode( ',', array_map( 'intval', $ids ) );
        if ( '' === $in ) {
            return 0;
        }
        $table = self::table();
        $busy  = implode( ',', array_fill( 0, count( self::IN_PROGRESS ), '%s' ) );
        // A waiting run its job took meanwhile is running now.
        $ids = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built to match the array.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of ints.
                "SELECT id FROM {$table} WHERE id IN ({$in}) AND status NOT IN ({$busy})",
                self::IN_PROGRESS
            )
        );
        $in = implode( ',', array_map( 'intval', (array) $ids ) );
        if ( '' === $in ) {
            return 0;
        }
        $changed = [];
        $tables  = [
            self::table()         => [ 'id', [ 'scope' ], [ 'error' ] ],
            RunStepStore::table() => [ 'run_id', [ 'input', 'output' ], [ 'error' ] ],
        ];
        foreach ( $tables as $table => list( $run_column, $json_columns, $text_columns ) ) {
            $columns = implode( ', ', array_merge( [ 'id', $run_column ], $json_columns, $text_columns ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- columns are ours, $in is a list of ints.
            $rows = (array) $wpdb->get_results( "SELECT {$columns} FROM {$table} WHERE {$run_column} IN ({$in})", ARRAY_A );
            foreach ( $rows as $row ) {
                $update = [];
                foreach ( $json_columns as $column ) {
                    $update[ $column ] = self::replace_json( (string) $row[ $column ], $email );
                }
                foreach ( $text_columns as $column ) {
                    $update[ $column ] = self::replace_text( (string) $row[ $column ], $email );
                }
                $update = array_filter( $update, fn( $value, $column ) => $value !== (string) $row[ $column ], ARRAY_FILTER_USE_BOTH );
                if ( ! $update ) {
                    continue;
                }
                $done = $wpdb->update( $table, $update, [ 'id' => (int) $row['id'] ] );
                if ( false === $done ) {
                    Logger::error( 'Could not erase an email from a run: ' . $wpdb->last_error, [ 'run_id' => (int) $row[ $run_column ] ] );
                } elseif ( $done ) {
                    $changed[ (int) $row[ $run_column ] ] = true;
                }
            }
        }
        return count( $changed );
    }

    /**
     * `LIKE` value for the email as a whole JSON string; case-insensitive by the tables' `_ci` collation.
     *
     * @param string $email
     */
    private static function json_like( string $email ): string {
        global $wpdb;
        return '%' . $wpdb->esc_like( (string) wp_json_encode( $email ) ) . '%';
    }

    /**
     * `REGEXP` value with the same boundaries as `replace_text()`; works with ICU (MySQL 8) and PCRE (MariaDB).
     *
     * @param string $email
     */
    private static function text_regexp( string $email ): string {
        // A dot after the address ends it unless a letter or digit follows: `bob@x.test.` ending a sentence
        // matches, `bob@x.test.au` doesn't.
        return '(^|[^A-Za-z0-9._%+-])' . addcslashes( $email, '.[]()*+?{}|^$\\' ) . '($|[^A-Za-z0-9.-]|[.]($|[^A-Za-z0-9]))';
    }

    /**
     * @param string $json
     * @param string $email
     */
    private static function replace_json( string $json, string $email ): string {
        return str_ireplace( (string) wp_json_encode( $email ), '"[deleted]"', $json );
    }

    /**
     * @param string $text
     * @param string $email
     */
    private static function replace_text( string $text, string $email ): string {
        return (string) preg_replace( '/(?<![A-Za-z0-9._%+-])' . preg_quote( $email, '/' ) . '(?![A-Za-z0-9-]|\.[A-Za-z0-9])/i', '[deleted]', $text );
    }

    public static function table(): string {
        return Schema::table( Schema::RUNS );
    }

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
