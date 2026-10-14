<?php
namespace YayAutomation\Workflow\Runtime;

use YayAutomation\Utils\Logger;
use YayAutomation\Workflow\Data\GoneEntity;
use YayAutomation\Workflow\Data\Ref;
use YayAutomation\Workflow\Data\RefResolver;
use YayAutomation\Workflow\Storage\RunStore;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a run's next slice when its Action Scheduler job fires. State lives in `runs` only, never in
 * Action Scheduler's tables: the job just says "run slice N of run X".
 *
 * - Takes the run with a row lock (`claim()`), so two jobs never run it at once. A waiting run is
 *   only taken once its wait is over; a job that comes early makes sure one comes at `wait_until`.
 * - Before the first step: workflow deleted or off → `cancelled`; an entity the trigger refers to
 *   is gone → `skipped`, with the reason.
 * - A job that died (fatal error, timeout) gets a recovery job, which may take the run from `running`;
 *   the Executor then fails the interrupted step instead of running it twice.
 * - While a run runs, it is `current()`: events it causes carry it as their parent.
 *
 * Never throws: errors go to the run or to `Logger`.
 */
final class Worker {

    /** @var RunRepository */
    private $runs;

    /** @var GraphSource */
    private $graphs;

    /** @var Queue */
    private $queue;

    /** @var Workflows */
    private $workflows;

    /** @var callable fn(): Clock, one per slice. */
    private $clock;

    /** @var callable|null fn( string $id ): ?Step, for the Executor. */
    private $steps;

    /** @var callable fn(): RefResolver, one per slice. */
    private $resolver;

    /** @var array|null The run being run now. */
    private $current = null;

    /**
     * @param RunRepository $runs      Runs and step rows.
     * @param GraphSource   $graphs    Pinned version graphs.
     * @param Queue         $queue     Action Scheduler.
     * @param Workflows     $workflows Workflow lookups.
     * @param callable|null $clock     New Clock per slice; null = SystemClock.
     * @param callable|null $steps     Step by id; null = `StepRegistry::get()`.
     * @param callable|null $resolver  New RefResolver per slice; null = the WooCommerce one.
     */
    public function __construct( RunRepository $runs, GraphSource $graphs, Queue $queue, Workflows $workflows, ?callable $clock = null, ?callable $steps = null, ?callable $resolver = null ) {
        $this->runs      = $runs;
        $this->graphs    = $graphs;
        $this->queue     = $queue;
        $this->workflows = $workflows;
        $this->clock     = $clock ?? fn() => new SystemClock();
        $this->steps     = $steps;
        $this->resolver  = $resolver ?? fn() => new RefResolver();
    }

    /** Hooks the run job, the recovery job and Action Scheduler's failure actions. */
    public function register(): void {
        add_action( ActionSchedulerQueue::HOOK, fn( $run_id ) => $this->run( (int) $run_id ), 10, 1 );
        add_action( ActionSchedulerQueue::RECOVER_HOOK, fn( $run_id ) => $this->run( (int) $run_id, true ), 10, 1 );
        foreach ( [ 'action_scheduler_failed_execution', 'action_scheduler_failed_action', 'action_scheduler_unexpected_shutdown' ] as $hook ) {
            add_action( $hook, [ $this, 'action_failed' ], 10, 1 );
        }
    }

    /**
     * Action Scheduler gave up on a job. If it was one of our run jobs, queue a recovery job.
     *
     * @param int $action_id Action Scheduler action id.
     */
    public function action_failed( $action_id ): void {
        try {
            if ( ! class_exists( '\ActionScheduler' ) ) {
                return;
            }
            $action = \ActionScheduler::store()->fetch_action( (string) $action_id );
            $this->recover_job( (string) $action->get_hook(), (array) $action->get_args() );
        } catch ( \Throwable $e ) {
            Logger::error( 'Could not recover a run: ' . $e->getMessage(), [ 'action_id' => $action_id ] );
        }
    }

    /**
     * Queues a recovery job for a dead run job. A dead recovery job isn't retried again.
     *
     * @param string $hook Hook of the dead job.
     * @param array  $args Its args.
     */
    public function recover_job( string $hook, array $args ): void {
        $run_id = (int) ( $args[0] ?? 0 );
        if ( ActionSchedulerQueue::HOOK !== $hook || $run_id <= 0 ) {
            return;
        }
        if ( ! $this->queue->recover( $run_id ) ) {
            Logger::error( "Run {$run_id}: could not queue its recovery.", [ 'run_id' => $run_id ] );
        }
    }

    /**
     * Runs the run's next slice, if this call gets it.
     *
     * @param int  $run_id  Run id.
     * @param bool $recover A recovery job: may take the run from `running` too.
     * @return string|null The run's status afterwards; null when the run wasn't taken.
     */
    public function run( int $run_id, bool $recover = false ): ?string {
        try {
            $from = [ RunStore::STATUS_QUEUED, RunStore::STATUS_WAITING ];
            if ( $recover ) {
                $from[] = RunStore::STATUS_RUNNING;
            }
            if ( ! $this->runs->claim_run( $run_id, $from ) ) {
                $this->keep_waiting( $run_id );
                return null;
            }
            $run = $this->runs->find_run( $run_id );
            if ( ! $run ) {
                return null;
            }

            $resolver = ( $this->resolver )();
            if ( null === $run['position'] ) {
                $ended = $this->start( $run, $resolver );
                if ( $ended ) {
                    return $ended;
                }
            }

            $this->current = $run;
            $executor      = new Executor( $this->runs, $this->graphs, $this->queue, ( $this->clock )(), $this->steps, fn() => $resolver );
            return $executor->run( $run );
        } catch ( \Throwable $e ) {
            Logger::error( "Run {$run_id} failed: " . $e->getMessage(), [ 'run_id' => $run_id ] );
            try {
                $this->runs->save_run(
                    $run_id,
                    [
                        'status' => RunStore::STATUS_FAILED,
                        'error'  => $e->getMessage(),
                    ]
                );
            } catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- already logged.
            }
            return RunStore::STATUS_FAILED;
        } finally {
            $this->current = null;
        }//end try
    }

    /**
     * The run being run now: its parent for events it causes. Null outside a run.
     *
     * @return array|null `id`, `workflow_id`, `root_run_id`, `depth`.
     */
    public function current(): ?array {
        return $this->current;
    }

    /**
     * A job that came before the run's wait is over can't take it. Make sure a job still comes at
     * `wait_until` (the queue adds none when one is already pending), so a waiting run is never
     * left without one.
     *
     * @param int $run_id Run id.
     */
    private function keep_waiting( int $run_id ): void {
        $run = $this->runs->find_run( $run_id );
        if ( ! $run || RunStore::STATUS_WAITING !== $run['status'] || empty( $run['wait_until'] ) ) {
            return;
        }
        $until = (int) strtotime( $run['wait_until'] . ' UTC' );
        if ( $until > ( $this->clock )()->now() && ! $this->queue->enqueue( $run_id, $until ) ) {
            Logger::error( "Run {$run_id}: could not queue the end of its wait.", [ 'run_id' => $run_id ] );
        }
    }

    /**
     * Checks before the first step. Ends the run and returns its status when it shouldn't start.
     *
     * @param array       $run      Run row.
     * @param RefResolver $resolver This slice's resolver; loads the trigger's entities once.
     */
    private function start( array $run, RefResolver $resolver ): ?string {
        $workflow = $this->workflows->find( (int) $run['workflow_id'] );
        if ( ! $workflow || 'active' !== ( $workflow['status'] ?? '' ) ) {
            $reason = $workflow
                ? __( 'The workflow was deactivated before this run started.', 'yay-automation' )
                : __( 'The workflow was deleted before this run started.', 'yay-automation' );
            return $this->end( (int) $run['id'], RunStore::STATUS_CANCELLED, $reason );
        }
        try {
            foreach ( (array) ( $run['scope']['trigger'] ?? [] ) as $value ) {
                $ref = Ref::from_json( $value );
                if ( $ref ) {
                    $resolver->load( $ref );
                }
            }
        } catch ( GoneEntity $gone ) {
            return $this->end( (int) $run['id'], RunStore::STATUS_SKIPPED, Executor::gone_message( $gone ) );
        }
        return null;
    }

    /**
     * @param int    $id     Run id.
     * @param string $status Finishing status.
     * @param string $reason Why, kept in `error`.
     */
    private function end( int $id, string $status, string $reason ): string {
        $this->runs->save_run(
            $id,
            [
                'status' => $status,
                'error'  => $reason,
            ]
        );
        return $status;
    }
}
