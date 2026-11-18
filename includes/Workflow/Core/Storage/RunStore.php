<?php
namespace YayMatik\Workflow\Core\Storage;

use YayMatik\Utils\Logger;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;

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
     * A queued run, or a skipped one that never runs; one with no parent is its own root.
     *
     * @param array $run `workflow_id`, `version_id`, `trigger_id`, `subject`, `scope` (array), and when
     *                   another run started it `parent_run_id`, `root_run_id`, `depth`; `status` skipped
     *                   with its `error` for a run a trigger refused.
     * @return int Run id; 0 when the insert failed (logged).
     */
    public static function create( array $run ): int {
        global $wpdb;
        $scope   = (string) wp_json_encode( $run['scope'] ?? [] );
        $skipped = self::STATUS_SKIPPED === ( $run['status'] ?? '' );
        $now     = current_time( 'mysql', true );
        $ok      = $wpdb->insert(
            self::table(),
            [
                'workflow_id'   => (int) $run['workflow_id'],
                'version_id'    => (int) $run['version_id'],
                'trigger_id'    => (string) ( $run['trigger_id'] ?? '' ),
                'subject'       => (string) ( $run['subject'] ?? '' ),
                'status'        => $skipped ? self::STATUS_SKIPPED : self::STATUS_QUEUED,
                'scope'         => $scope,
                'error'         => $skipped ? (string) ( $run['error'] ?? '' ) : null,
                'parent_run_id' => (int) ( $run['parent_run_id'] ?? 0 ),
                'root_run_id'   => (int) ( $run['root_run_id'] ?? 0 ),
                'depth'         => (int) ( $run['depth'] ?? 0 ),
                'started_at'    => $now,
                'finished_at'   => $skipped ? $now : null,
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ]
        );
        if ( ! $ok ) {
            Logger::error( 'Could not create a run: ' . $wpdb->last_error, [ 'workflow_id' => (int) $run['workflow_id'] ] );
            return 0;
        }
        $id = (int) $wpdb->insert_id;
        if ( empty( $run['root_run_id'] ) ) {
            $wpdb->update( self::table(), [ 'root_run_id' => $id ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        }
        // Rows a failed delete left for this id belong to an older run: MySQL 5.7 can hand out an id again after a restart.
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE run_id = %d', Schema::table( Schema::RUN_EMAILS ), $id ) );
        self::index_emails( [ $scope ], $skipped ? [ (string) ( $run['error'] ?? '' ) ] : [], 'SELECT %d AS run_id', [ $id ] );
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
        $row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
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

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $filter has placeholders for $params.
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $filter has placeholders for $params.
            $wpdb->prepare( "SELECT id, workflow_id, version_id, trigger_id, subject, status, position, wait_until, error, parent_run_id, root_run_id, depth, started_at, finished_at FROM %i {$filter} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( [ self::table() ], $params ) ),
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
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $filter has placeholders for $params.
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i {$filter}", array_merge( [ self::table() ], $params ) ) );
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
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema::in() gives one %d per workflow.
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Schema::in() gives one %d per workflow.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                'SELECT s.workflow_id, s.recent, s.waiting, s.last_id, r.started_at AS last_run, r.status AS last_status FROM (SELECT workflow_id, SUM(started_at >= %s) AS recent, SUM(status = %s) AS waiting, MAX(id) AS last_id FROM %i WHERE workflow_id IN (' . Schema::in( count( $ids ) ) . ') GROUP BY workflow_id) s INNER JOIN %i r ON r.id = s.last_id',
                array_merge( [ gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 ), self::STATUS_WAITING, self::table() ], array_values( $ids ), [ self::table() ] )
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
            $where[] = 'status IN (' . Schema::in( count( $statuses ), '%s' ) . ')';
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
        $row    = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS total, COALESCE(SUM(depth > 0), 0) AS chained FROM %i WHERE workflow_id = %d AND started_at >= %s', $table, $workflow_id, $cutoff ), ARRAY_A );
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
            $wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s WHERE id = %d AND status = %s', $table, self::STATUS_QUEUED, $id, self::STATUS_RUNNING ) );
            $from = array_values( array_unique( array_merge( array_diff( $from, [ self::STATUS_RUNNING ] ), [ self::STATUS_QUEUED ] ) ) );
        }
        // A waiting run only once its wait is over; `wait_until` stays, so the Executor can tell a woken
        // wait from an interrupted one.
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema::in() gives the list placeholders.
        $done = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built to match the array.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Schema::in() gives the list placeholders.
                'UPDATE %i SET status = %s WHERE id = %d AND status IN (' . Schema::in( count( $from ), '%s' ) . ') AND ( status <> %s OR wait_until IS NULL OR wait_until <= %s )',
                array_merge( [ $table, self::STATUS_RUNNING, $id ], $from, [ self::STATUS_WAITING, current_time( 'mysql', true ) ] )
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
        $ids   = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE workflow_id = %d AND status = %s', $table, $workflow_id, self::STATUS_WAITING ) );
        return array_map( 'intval', (array) $ids );
    }

    /**
     * Waiting runs about these subjects.
     *
     * @param string[] $subjects
     * @return int[]
     */
    public static function waiting_ids_for_subjects( array $subjects ): array {
        global $wpdb;
        if ( ! $subjects ) {
            return [];
        }
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Schema::in() gives one %s per subject.
        $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE status = %s AND subject IN (' . Schema::in( count( $subjects ), '%s' ) . ')', array_merge( [ self::table(), self::STATUS_WAITING ], array_values( $subjects ) ) ) );
        return array_map( 'intval', (array) $ids );
    }

    /**
     * Runs that should have a job by now: queued since before `$queued_before`, waiting past `$waiting_before`, any running; oldest first.
     *
     * @param string $queued_before GMT datetime.
     * @param string $waiting_before GMT datetime.
     * @param int    $limit
     * @return array[] `id`, `status`, `started_at`, `wait_until`.
     */
    public static function overdue( string $queued_before, string $waiting_before, int $limit ): array {
        global $wpdb;
        $table = self::table();
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, status, started_at, wait_until FROM %i WHERE (status = %s AND started_at < %s) OR (status = %s AND wait_until < %s) OR status = %s ORDER BY id LIMIT %d',
                $table,
                self::STATUS_QUEUED,
                $queued_before,
                self::STATUS_WAITING,
                $waiting_before,
                self::STATUS_RUNNING,
                $limit
            ),
            ARRAY_A
        );
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
        $ids = array_map( 'intval', array_values( $ids ) );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema::in() gives the list placeholders.
        $done = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Schema::in() gives one %d per run.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Schema::in() gives the list placeholders.
                'UPDATE %i SET status = %s, error = %s, wait_until = NULL, finished_at = %s WHERE id IN (' . Schema::in( count( $ids ) ) . ') AND status = %s',
                array_merge( [ self::table(), self::STATUS_CANCELLED, $reason, current_time( 'mysql', true ) ], $ids, [ self::STATUS_WAITING ] )
            )
        );
        if ( false === $done ) {
            Logger::error( 'Could not cancel waiting runs: ' . $wpdb->last_error, [ 'run_ids' => $ids ] );
            return 0;
        }
        if ( $done ) {
            // Only the runs this call ended: one its job took meanwhile never got the reason.
            self::index_emails( [], [ $reason ], 'SELECT id AS run_id FROM %i WHERE id IN (' . Schema::in( count( $ids ) ) . ') AND status = %s AND error = %s', array_merge( [ self::table() ], $ids, [ self::STATUS_CANCELLED, $reason ] ) );
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
        self::index_emails( array_filter( [ $row['scope'] ?? null ] ), array_filter( [ $row['error'] ?? null ] ), 'SELECT id AS run_id FROM %i WHERE id = %d', [ self::table(), $id ] );
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
        $table = self::table();
        $open  = array_values( array_diff( self::STATUSES, self::FINISHED ) );
        // A chain with an open run keeps all its runs: the loop guard walks parent_run_id and stops at a missing row.
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema::in() gives the list placeholders.
        $ids = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built to match the array.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Schema::in() gives the list placeholders.
                'SELECT r.id FROM %i r WHERE r.status IN (' . Schema::in( count( self::FINISHED ), '%s' ) . ') AND r.finished_at < %s AND NOT EXISTS (SELECT 1 FROM %i o WHERE o.root_run_id = r.root_run_id AND o.status IN (' . Schema::in( count( $open ), '%s' ) . ')) ORDER BY r.id LIMIT %d',
                array_merge( [ $table ], self::FINISHED, [ $before, $table ], $open, [ $limit ] )
            )
        );
        return array_map( 'intval', (array) $ids );
    }

    /**
     * Steps first, so a failure in between leaves no steps without their run; index rows last, as one left over is never joined.
     *
     * @param int[] $ids
     */
    public static function delete_with_steps( array $ids ): bool {
        global $wpdb;
        $ids = array_map( 'intval', array_values( $ids ) );
        if ( ! $ids ) {
            return true;
        }
        $in = Schema::in( count( $ids ) );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Schema::in() gives one %d per run.
        if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE run_id IN ({$in})", array_merge( [ RunStepStore::table() ], $ids ) ) ) || false === $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$in})", array_merge( [ self::table() ], $ids ) ) ) || false === $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE run_id IN ({$in})", array_merge( [ Schema::table( Schema::RUN_EMAILS ) ], $ids ) ) ) ) {
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
        list( $about, $params ) = self::about( $email, $subjects, false );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $about has placeholders for $params.
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built to match the array.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $about has placeholders for $params.
                "SELECT r.id, r.status, r.started_at, w.name AS workflow_name FROM %i r INNER JOIN {$about} ON m.id = r.id LEFT JOIN %i w ON w.id = r.workflow_id ORDER BY r.id LIMIT %d OFFSET %d",
                array_merge( [ self::table() ], $params, [ Schema::table( Schema::WORKFLOWS ), $limit, $offset ] )
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
        $busy                   = Schema::in( count( self::IN_PROGRESS ), '%s' );
        list( $about, $params ) = self::about( $email, $subjects, true );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $busy and $about are placeholders.
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built to match the array.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $busy and $about are placeholders.
                "SELECT r.id, r.status FROM %i r INNER JOIN {$about} ON m.id = r.id WHERE r.status NOT IN ({$busy}) ORDER BY r.id LIMIT %d",
                array_merge( [ self::table() ], $params, self::IN_PROGRESS, [ $limit ] )
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
        $busy                   = Schema::in( count( self::IN_PROGRESS ), '%s' );
        list( $about, $params ) = self::about( $email, $subjects, false );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $busy and $about are placeholders.
        $found = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built to match the array.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $busy and $about are placeholders.
                "SELECT r.id FROM %i r INNER JOIN {$about} ON m.id = r.id WHERE r.status IN ({$busy}) LIMIT 1",
                array_merge( [ self::table() ], $params, self::IN_PROGRESS )
            )
        );
        return null !== $found;
    }

    /**
     * Derived table `m` of the ids of runs whose index holds the email or claim key, or about one of the subjects.
     *
     * @param string   $email
     * @param string[] $subjects
     * @param bool     $waiting_subjects Subjects match waiting runs only: a finished run about them has nothing to erase.
     * @return array [ string $sql, array $params ]
     */
    private static function about( string $email, array $subjects, bool $waiting_subjects ): array {
        // A guest's milestone run holds only the claim key made from the email; a run a trigger started holds
        // refs, not the email, so it is found by its subject.
        $sql    = 'SELECT run_id AS id FROM %i WHERE email IN (%s, %s)';
        $params = [ Schema::table( Schema::RUN_EMAILS ), self::index_key( $email ), self::index_key( TriggerClaimStore::email_key( $email ) ) ];
        if ( $subjects ) {
            $sql   .= ' UNION SELECT id FROM %i WHERE subject IN (' . Schema::in( count( $subjects ), '%s' ) . ')' . ( $waiting_subjects ? ' AND status = %s' : '' );
            $params = array_merge( $params, [ self::table() ], array_values( $subjects ), $waiting_subjects ? [ self::STATUS_WAITING ] : [] );
        }
        return [ "({$sql}) m", $params ];
    }

    /**
     * The email → `[deleted]` in runs' scope and error, steps' input, output and error; statuses and dates stay.
     *
     * @param int[]  $ids Queued and running ones are left alone.
     * @param string $email Normalized.
     * @return int Runs with a row changed or their index link to the email removed.
     */
    public static function anonymize( array $ids, string $email ): int {
        global $wpdb;
        $ids = array_map( 'intval', array_values( $ids ) );
        if ( ! $ids ) {
            return 0;
        }
        // A waiting run its job took meanwhile is running now.
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema::in() gives the list placeholders.
        $ids = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built to match the array.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Schema::in() gives the list placeholders.
                'SELECT id FROM %i WHERE id IN (' . Schema::in( count( $ids ) ) . ') AND status NOT IN (' . Schema::in( count( self::IN_PROGRESS ), '%s' ) . ')',
                array_merge( [ self::table() ], $ids, self::IN_PROGRESS )
            )
        );
        $ids = array_map( 'intval', (array) $ids );
        if ( ! $ids ) {
            return 0;
        }
        $changed = [];
        $failed  = [];
        $tables  = [
            self::table()         => [ 'id', [ 'scope' ], [ 'error' ] ],
            RunStepStore::table() => [ 'run_id', [ 'input', 'output' ], [ 'error' ] ],
        ];
        foreach ( $tables as $table => list( $run_column, $json_columns, $text_columns ) ) {
            $columns = array_merge( [ 'id', $run_column ], $json_columns, $text_columns );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema::in() gives the list placeholders.
            $rows = (array) $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one value per %i and %d.
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Schema::in() gives the list placeholders.
                    'SELECT ' . Schema::in( count( $columns ), '%i' ) . ' FROM %i WHERE %i IN (' . Schema::in( count( $ids ) ) . ')',
                    array_merge( $columns, [ $table, $run_column ], $ids )
                ),
                ARRAY_A
            );
            // An unread table may still hold the email: keep every run findable for the next erase.
            if ( '' !== $wpdb->last_error ) {
                Logger::error( 'Could not read runs to erase: ' . $wpdb->last_error, [ 'run_ids' => $ids ] );
                return count( $changed );
            }
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
                }
                // No row changed, so the email may still be there.
                if ( ! $done ) {
                    $failed[ (int) $row[ $run_column ] ] = true;
                } else {
                    $changed[ (int) $row[ $run_column ] ] = true;
                }
            }
        }
        // A run whose scrub failed stays findable, so the next erase tries again.
        $ids = array_values( array_diff( $ids, array_keys( $failed ) ) );
        if ( ! $ids ) {
            return count( $changed );
        }
        // A cut address may be another's too, so its link stays: a later erase only visits the run again.
        $keys = array_values( array_filter( [ strlen( $email ) <= 190 ? $email : '', TriggerClaimStore::email_key( $email ) ] ) );
        $in   = Schema::in( count( $ids ) );
        $of   = Schema::in( count( $keys ), '%s' );
        // A link left by an overwritten value counts as erased, so a page of only those isn't reported as failed.
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Schema::in() gives the list placeholders.
        $linked = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT run_id FROM %i WHERE run_id IN ({$in}) AND email IN ({$of})", array_merge( [ Schema::table( Schema::RUN_EMAILS ) ], $ids, $keys ) ) );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Schema::in() gives the list placeholders.
        if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE run_id IN ({$in}) AND email IN ({$of})", array_merge( [ Schema::table( Schema::RUN_EMAILS ) ], $ids, $keys ) ) ) ) {
            Logger::error( 'Could not remove an erased email from the run index: ' . $wpdb->last_error, [ 'run_ids' => $ids ] );
            return count( $changed );
        }
        return count( $changed + array_fill_keys( array_map( 'intval', $linked ), true ) );
    }

    /**
     * Adds the emails in these values to `run_emails` for the runs `$runs` selects; logs a failure, never throws.
     *
     * @param string[] $json Values of JSON columns, as stored.
     * @param string[] $text Values of text columns.
     * @param string   $runs `SELECT` of a `run_id` column; its placeholders take `$params`.
     * @param array    $params
     */
    public static function index_emails( array $json, array $text, string $runs, array $params ): void {
        global $wpdb;
        try {
            $emails = self::emails_in( $json, $text );
            if ( ! $emails ) {
                return;
            }
            $list = implode( ' UNION ALL ', array_fill( 0, count( $emails ), 'SELECT %s AS email' ) );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $runs and $list are placeholders.
            $done = $wpdb->query(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built to match the array.
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $runs and $list are placeholders.
                    "INSERT IGNORE INTO %i (run_id, email) SELECT r.run_id, e.email FROM ({$runs}) r CROSS JOIN ({$list}) e",
                    array_merge( [ Schema::table( Schema::RUN_EMAILS ) ], $params, $emails )
                )
            );
            if ( false === $done ) {
                Logger::error( 'Could not index a run\'s emails: ' . $wpdb->last_error, [ 'runs' => $params ] );
            }
        } catch ( \Throwable $e ) {
            Logger::error( 'Could not index a run\'s emails: ' . $e->getMessage(), [ 'runs' => $params ] );
        }
    }

    /**
     * Index values: JSON strings `replace_json()` would replace, and text addresses `replace_text()` would.
     *
     * @param string[] $json
     * @param string[] $text
     * @return string[]
     */
    private static function emails_in( array $json, array $text ): array {
        $found = [];
        foreach ( $json as $value ) {
            self::json_emails( json_decode( (string) $value, true ), $found );
        }
        foreach ( $text as $value ) {
            self::text_emails( (string) $value, $found );
        }
        return array_keys( $found );
    }

    /**
     * @param mixed $value Decoded JSON.
     * @param array $found Index value => true.
     */
    private static function json_emails( $value, array &$found ): void {
        if ( is_string( $value ) ) {
            $lower = strtolower( $value );
            // Untrimmed, as `replace_json()` replaces only the whole JSON string.
            if ( ( false !== strpos( $value, '@' ) && UnsubscribeLink::normalize( $value ) === $lower ) || preg_match( '/^email:[0-9a-f]{64}$/', $lower ) ) {
                $found[ self::index_key( $lower ) ] = true;
            }
            return;
        }
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $item ) {
                // JSON object keys were in the scanned text too.
                if ( is_string( $key ) ) {
                    self::json_emails( $key, $found );
                }
                self::json_emails( $item, $found );
            }
        }
    }

    /**
     * Each `@` with every start and end `replace_text()`'s boundaries allow, kept when it is an email.
     *
     * @param string $text
     * @param array  $found Index value => true.
     */
    private static function text_emails( string $text, array &$found ): void {
        $alnum = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $in    = fn( string $char, string $chars ) => '' !== $char && false !== strpos( $chars, $char );
        $at    = strpos( $text, '@' );
        while ( false !== $at ) {
            $starts = [];
            $i      = $at - 1;
            while ( $i >= 0 && $in( $text[ $i ], $alnum . "!#$%&'*+/=?^_`{|}~.-" ) ) {
                // Not `$text[-1]`: a negative offset reads from the end.
                if ( 0 === $i || ! $in( $text[ $i - 1 ], $alnum . '._%+-' ) ) {
                    $starts[] = $i;
                }
                --$i;
            }
            $ends = [];
            $i    = $at + 1;
            while ( $in( $text[ $i ] ?? '', $alnum . '.-' ) ) {
                ++$i;
                $next = $text[ $i ] ?? '';
                if ( ! $in( $next, $alnum . '-' ) && ! ( '.' === $next && $in( $text[ $i + 1 ] ?? '', $alnum ) ) ) {
                    $ends[] = $i;
                }
            }
            foreach ( $starts as $start ) {
                foreach ( $ends as $end ) {
                    $email = strtolower( substr( $text, $start, $end - $start ) );
                    if ( is_email( $email ) ) {
                        $found[ self::index_key( $email ) ] = true;
                    }
                }
            }
            $at = strpos( $text, '@', $at + 1 );
        }
    }

    /**
     * An email or claim key as `run_emails` keeps it.
     *
     * @param string $email Lowercased.
     */
    private static function index_key( string $email ): string {
        return substr( $email, 0, 190 );
    }

    /**
     * @param string $json
     * @param string $email
     */
    private static function replace_json( string $json, string $email ): string {
        // A guest's milestone run keeps the claim key made from the email.
        $found = [ (string) wp_json_encode( $email ), (string) wp_json_encode( TriggerClaimStore::email_key( $email ) ) ];
        return str_ireplace( $found, '"[deleted]"', $json );
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
