<?php
namespace YayMatik\Workflow\Core\Runtime;

use YayMatik\Workflow\Core\Storage\RunStore;

defined( 'ABSPATH' ) || exit;

/** Finds runs and jobs Action Scheduler left behind; each check reports data only, the admin app words it. */
final class HealthCheck {

    /** Check id => fix id; `jobs_late` has none (the server's cron is the fix). */
    public const FIXES = [
        'runs_queued'  => 'enqueue',
        'runs_running' => 'recover',
        'cleanup_job'  => 'schedule',
    ];

    private const CRON_GUIDE = 'https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/';

    /** Run ids per check; `count` still counts them all. */
    private const LIMIT = 50;

    /** Candidate runs read per check; a full scan makes `count` a lower bound (`capped`). */
    private const SCAN = 500;

    // Seconds; literals, as PHPUnit runs without WordPress's time constants.
    private const QUEUED_AFTER  = 300;
    private const WAITING_AFTER = 900;
    private const LATE_AFTER    = 900;
    private const CLEANUP_LATE  = 3600;

    /** @return array[] Every check: `id`, `status` (ok|problem|info), `count`, `runIds`, `fix` (null unless a problem), `capped` (count is a lower bound). */
    public static function run(): array {
        $now  = time();
        $runs = self::runs( $now );
        return [
            self::result( 'runs_queued', $runs['runs_queued'] ),
            self::result( 'runs_running', $runs['runs_running'] ),
            self::jobs_late( $now ),
            self::cleanup_job( $now ),
        ];
    }

    /**
     * Runs the check again and fixes what it finds now.
     *
     * @param string $check A key of `FIXES`.
     * @return int Runs queued or recovered; 1 when the cleanup job was scheduled again.
     */
    public static function fix( string $check ): int {
        $now = time();
        if ( 'cleanup_job' === $check ) {
            if ( 'problem' !== self::cleanup_job( $now )['status'] ) {
                return 0;
            }
            Cleanup::reschedule();
            return 1;
        }
        $queue = new ActionSchedulerQueue();
        $fixed = 0;
        foreach ( self::runs( $now )[ $check ]['ids'] as $run_id ) {
            $done   = 'runs_queued' === $check ? $queue->enqueue( $run_id ) : $queue->recover( $run_id );
            $fixed += $done ? 1 : 0;
        }
        return $fixed;
    }

    /**
     * Stuck runs among the candidates, oldest first.
     *
     * @param array[]  $runs `id`, `status`, `started_at`, `wait_until` (GMT), oldest first.
     * @param callable $has_job fn( int $run_id, string $hook ): bool — a pending or in-progress job.
     * @param int      $now
     * @return array[] `runs_queued` / `runs_running` => [ `count`, `ids` (first 50), `capped` when `$runs` filled the scan ].
     */
    public static function classify( array $runs, callable $has_job, int $now ): array {
        $found = [
            'runs_queued'  => [],
            'runs_running' => [],
        ];
        foreach ( $runs as $run ) {
            $id = (int) $run['id'];
            switch ( $run['status'] ) {
                case RunStore::STATUS_QUEUED:
                    $overdue = self::before( $run['started_at'], $now - self::QUEUED_AFTER );
                    break;
                case RunStore::STATUS_WAITING:
                    $overdue = self::before( $run['wait_until'], $now - self::WAITING_AFTER );
                    break;
                default:
                    $overdue = RunStore::STATUS_RUNNING === $run['status'];
            }
            // A recovery job takes queued and waiting runs too.
            if ( $overdue && ! $has_job( $id, ActionSchedulerQueue::HOOK ) && ! $has_job( $id, ActionSchedulerQueue::RECOVER_HOOK ) ) {
                $found[ RunStore::STATUS_RUNNING === $run['status'] ? 'runs_running' : 'runs_queued' ][] = $id;
            }
        }
        $capped = count( $runs ) >= self::SCAN;
        return array_map(
            fn( array $ids ) => [
                'count' => count( $ids ),
                'ids'   => array_slice( $ids, 0, self::LIMIT ),
            ] + ( $capped ? [ 'capped' => true ] : [] ),
            $found
        );
    }

    /**
     * @param int $now
     */
    private static function runs( int $now ): array {
        $candidates = RunStore::overdue(
            gmdate( 'Y-m-d H:i:s', $now - self::QUEUED_AFTER ),
            gmdate( 'Y-m-d H:i:s', $now - self::WAITING_AFTER ),
            self::SCAN
        );
        return self::classify(
            $candidates,
            fn( int $run_id, string $hook ) => as_has_scheduled_action( $hook, [ $run_id ], ActionSchedulerQueue::GROUP ),
            $now
        );
    }

    /**
     * @param int $now
     */
    private static function jobs_late( int $now ): array {
        $count = (int) \ActionScheduler::store()->query_actions(
            [
                'group'        => ActionSchedulerQueue::GROUP,
                'status'       => \ActionScheduler_Store::STATUS_PENDING,
                'date'         => as_get_datetime_object( $now - self::LATE_AFTER ),
                'date_compare' => '<',
            ],
            'count'
        );
        return [
            'id'     => 'jobs_late',
            'status' => $count ? 'info' : 'ok',
            'count'  => $count,
            'runIds' => [],
            'fix'    => null,
            'guide'  => self::CRON_GUIDE,
        ];
    }

    /**
     * @param int $now
     */
    private static function cleanup_job( int $now ): array {
        // True = running now (or async); an int = the next run's time.
        $next    = as_next_scheduled_action( Cleanup::HOOK, [], ActionSchedulerQueue::GROUP );
        $problem = false === $next || ( is_int( $next ) && $next < $now - self::CLEANUP_LATE );
        return self::result(
            'cleanup_job',
            [
                'count' => $problem ? 1 : 0,
                'ids'   => [],
            ]
        );
    }

    /**
     * @param string $id
     * @param array  $found `count`, `ids`, `capped`?.
     */
    private static function result( string $id, array $found ): array {
        return [
            'id'     => $id,
            'status' => $found['count'] ? 'problem' : 'ok',
            'count'  => $found['count'],
            'runIds' => $found['ids'],
            'fix'    => $found['count'] ? self::FIXES[ $id ] : null,
        ] + ( empty( $found['capped'] ) ? [] : [ 'capped' => true ] );
    }

    /**
     * @param string|null $gmt
     * @param int         $cutoff
     */
    private static function before( ?string $gmt, int $cutoff ): bool {
        return ! empty( $gmt ) && strtotime( $gmt . ' UTC' ) < $cutoff;
    }
}
