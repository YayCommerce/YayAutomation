<?php
namespace YayMatik\Workflow\Core\Runtime;

use YayMatik\Utils\Logger;
use YayMatik\Workflow\Core\Data\GoneEntity;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Storage\RunStore;

defined( 'ABSPATH' ) || exit;

/** Runs a run's next slice when its job fires; never throws (errors go to the run or `Logger`). */
final class Worker {

    /** @var RunRepository */
    private $runs;

    /** @var GraphSource */
    private $graphs;

    /** @var Queue */
    private $queue;

    /** @var Workflows */
    private $workflows;

    /** @var callable fn(): Clock */
    private $clock;

    /** @var callable|null fn( string $id ): ?Step */
    private $steps;

    /** @var callable fn(): RefResolver */
    private $resolver;

    /** @var array|null */
    private $current = null;

    /**
     * @param RunRepository $runs
     * @param GraphSource   $graphs
     * @param Queue         $queue
     * @param Workflows     $workflows
     * @param callable|null $clock     New Clock per slice; null = SystemClock.
     * @param callable|null $steps     Null = `StepRegistry::get()`.
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

    public function register(): void {
        add_action( ActionSchedulerQueue::HOOK, fn( $run_id ) => $this->run( (int) $run_id ), 10, 1 );
        add_action( ActionSchedulerQueue::RECOVER_HOOK, fn( $run_id ) => $this->run( (int) $run_id, true ), 10, 1 );
        foreach ( [ 'action_scheduler_failed_execution', 'action_scheduler_failed_action', 'action_scheduler_unexpected_shutdown' ] as $hook ) {
            add_action( $hook, [ $this, 'action_failed' ], 10, 1 );
        }
    }

    /**
     * Action Scheduler gave up on a job (fatal error, timeout): a run job gets a recovery job.
     *
     * @param int $action_id
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
     * A dead recovery job isn't retried again.
     *
     * @param string $hook
     * @param array  $args
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
     * @param int  $run_id
     * @param bool $recover May take the run from `running` too; the Executor then fails the interrupted step.
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
            $executor      = new Executor( $this->runs, $this->graphs, $this->queue, $this->workflows, ( $this->clock )(), $this->steps, fn() => $resolver );
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
        }
    }

    /**
     * The run being run now, the parent of events it causes.
     *
     * @return array|null `id`, `workflow_id`, `root_run_id`, `depth`.
     */
    public function current(): ?array {
        return $this->current;
    }

    /**
     * A job that came before the wait is over can't take the run, so make sure one comes at `wait_until`.
     *
     * @param int $run_id
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
     * Ends the run and returns its status when it shouldn't start.
     *
     * @param array       $run
     * @param RefResolver $resolver
     */
    private function start( array $run, RefResolver $resolver ): ?string {
        $workflow_id = (int) $run['workflow_id'];
        if ( ! $this->workflows->is_active( $workflow_id ) ) {
            $reason = $this->workflows->find( $workflow_id )
                ? __( 'The workflow was deactivated before this run started.', 'yaymatik' )
                : __( 'The workflow was deleted before this run started.', 'yaymatik' );
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
     * A first step interrupted by a crash is still `running`: its row ends with the run.
     *
     * @param int    $id
     * @param string $status
     * @param string $reason
     */
    private function end( int $id, string $status, string $reason ): string {
        $this->runs->save_run(
            $id,
            [
                'status' => $status,
                'error'  => $reason,
            ]
        );
        $this->runs->close_steps( $id, $status, $reason );
        return $status;
    }
}
