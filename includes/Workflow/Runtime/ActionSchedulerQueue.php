<?php
namespace YayMatik\Workflow\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * `Queue` over Action Scheduler (bundled with WooCommerce). One job per run at a time: a run that
 * already has a pending job gets no second one, and a recovery job is only added when none is
 * pending or in progress (two could otherwise both take the run back from `running`).
 */
final class ActionSchedulerQueue implements Queue {

    /** Action Scheduler hook; args `[ run_id ]`. */
    public const HOOK = 'yaymatik_run';

    /** Recovery job for a run whose job died; args `[ run_id ]`. */
    public const RECOVER_HOOK = 'yaymatik_recover_run';

    public const GROUP = 'yaymatik';

    public function available(): bool {
        return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_single_action' )
            && function_exists( 'as_get_scheduled_actions' ) && function_exists( 'as_has_scheduled_action' );
    }

    public function enqueue( int $run_id, ?int $at = null ): bool {
        if ( ! $this->available() ) {
            return false;
        }
        try {
            // Pending only: the job running now (in progress) is the one asking for the next.
            $pending = as_get_scheduled_actions(
                [
                    'hook'     => self::HOOK,
                    'args'     => [ $run_id ],
                    'group'    => self::GROUP,
                    'status'   => 'pending',
                    'per_page' => 1,
                ],
                'ids'
            );
            if ( $pending ) {
                return true;
            }
            $job = null === $at
                ? as_enqueue_async_action( self::HOOK, [ $run_id ], self::GROUP )
                : as_schedule_single_action( $at, self::HOOK, [ $run_id ], self::GROUP );
        } catch ( \Throwable $e ) {
            return false;
        }//end try
        return (bool) $job;
    }

    public function recover( int $run_id ): bool {
        if ( ! $this->available() ) {
            return false;
        }
        try {
            if ( as_has_scheduled_action( self::RECOVER_HOOK, [ $run_id ], self::GROUP ) ) {
                return true;
            }
            return (bool) as_enqueue_async_action( self::RECOVER_HOOK, [ $run_id ], self::GROUP );
        } catch ( \Throwable $e ) {
            return false;
        }
    }
}
