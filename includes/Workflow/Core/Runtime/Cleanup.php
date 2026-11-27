<?php
namespace YayMatik\Workflow\Core\Runtime;

use YayMatik\Workflow\Core\Cart\CartSweep;
use YayMatik\Workflow\Core\Storage\CartStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\Settings;
use YayMatik\Workflow\Core\Storage\TriggerClaimStore;
use YayMatik\Workflow\Core\Storage\WorkflowStore;

defined( 'ABSPATH' ) || exit;

/** Daily job deleting finished runs and carts older than their retention settings, and deleted workflows' claims. */
final class Cleanup {

    public const HOOK = 'yaymatik_cleanup';

    /** Unix time of the last schedule check; autoloaded, so the hourly check costs no query. */
    public const CHECKED_OPTION = 'yaymatik_cleanup_checked_at';

    /** The daily job hooks at that check, sorted; autoloaded. A job added since (YayMatik Pro's) skips the hourly wait. */
    private const JOBS_OPTION = 'yaymatik_cleanup_jobs';

    private const BATCH = 500;

    /** Seconds; what's left goes the next day. */
    private const BUDGET = 20;

    public static function boot(): void {
        add_action( 'action_scheduler_init', [ self::class, 'ensure' ] );
        add_action( self::HOOK, [ self::class, 'run' ] );
    }

    /** Schedules this job, the cart sweep and the added daily jobs when missing, checked at most hourly or when the daily jobs change. */
    public static function ensure(): void {
        if ( ! function_exists( 'as_has_scheduled_action' ) ) {
            return;
        }
        $jobs = array_column( DailyJobs::all(), 'hook' );
        sort( $jobs );
        if ( get_option( self::JOBS_OPTION ) === $jobs && time() - (int) get_option( self::CHECKED_OPTION, 0 ) < HOUR_IN_SECONDS ) {
            return;
        }
        if ( ! as_has_scheduled_action( self::HOOK, [], ActionSchedulerQueue::GROUP ) ) {
            self::schedule();
        }
        if ( ! as_has_scheduled_action( CartSweep::HOOK, [], ActionSchedulerQueue::GROUP ) ) {
            CartSweep::schedule();
        }
        foreach ( DailyJobs::all() as $job ) {
            if ( ! as_has_scheduled_action( $job['hook'], [], ActionSchedulerQueue::GROUP ) ) {
                ( $job['schedule'] )();
            }
        }
        update_option( self::CHECKED_OPTION, time(), true );
        update_option( self::JOBS_OPTION, $jobs, true );
    }

    /** Replaces a missing or late job with a new one. */
    public static function reschedule(): void {
        as_unschedule_all_actions( self::HOOK, [], ActionSchedulerQueue::GROUP );
        self::schedule();
        update_option( self::CHECKED_OPTION, time(), true );
    }

    private static function schedule(): void {
        as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, [], ActionSchedulerQueue::GROUP, true );
    }

    /** A job nothing runs would fail every day in Action Scheduler's log. */
    public static function unschedule_left_over(): void {
        $added = array_column( DailyJobs::all(), 'hook' );
        foreach ( DailyJobs::LEFT_OVER as $job => $hooks ) {
            if ( in_array( $job, $added, true ) ) {
                continue;
            }
            foreach ( $hooks as $hook ) {
                // No group: with one, only actions without args would go, and the batches have args.
                if ( as_has_scheduled_action( $hook ) ) {
                    as_unschedule_all_actions( $hook );
                }
            }
        }
    }

    public static function run(): void {
        // Repairs a list a lost race or a lock timeout left wrong.
        WorkflowStore::refresh_active_triggers();
        self::unschedule_left_over();
        $deadline = microtime( true ) + self::BUDGET;
        // Carts first: they hold emails, and the privacy text promises their deletion; runs get what's left.
        $carts = gmdate( 'Y-m-d H:i:s', time() - (int) Settings::get( 'cart_retention_days' ) * DAY_IN_SECONDS );
        do {
            $ids = CartStore::inactive_ids_before( $carts, self::BATCH );
            Cancellation::carts( $ids, Cancellation::cart_gone() );
            $deleted = CartStore::delete_ids( $ids );
        } while ( self::BATCH === $deleted && microtime( true ) < $deadline );

        do {
            $deleted = TriggerClaimStore::delete_orphans( self::BATCH );
        } while ( self::BATCH === $deleted && microtime( true ) < $deadline );

        $runs = gmdate( 'Y-m-d H:i:s', time() - (int) Settings::get( 'run_retention_days' ) * DAY_IN_SECONDS );
        while ( microtime( true ) < $deadline ) {
            $ids = RunStore::finished_before( $runs, self::BATCH );
            if ( ! $ids || ! RunStore::delete_with_steps( $ids ) || self::BATCH !== count( $ids ) ) {
                return;
            }
        }
    }
}
