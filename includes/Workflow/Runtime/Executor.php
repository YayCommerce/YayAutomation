<?php
namespace YayMatik\Workflow\Runtime;

use YayMatik\Utils\Logger;
use YayMatik\Workflow\Data\DataRegistry;
use YayMatik\Workflow\Data\GoneEntity;
use YayMatik\Workflow\Data\RefResolver;
use YayMatik\Workflow\Data\Scope;
use YayMatik\Workflow\Steps\Core\Condition;
use YayMatik\Workflow\Steps\Core\Step;
use YayMatik\Workflow\Steps\Core\StepRegistry;
use YayMatik\Workflow\Steps\Core\StepResult;
use YayMatik\Workflow\Storage\RunStepStore;
use YayMatik\Workflow\Storage\RunStore;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one slice of a claimed run (status `running`) over the graph of the version it pinned.
 *
 * - Cursor: `position` = `{ "queue": [ pending step ids ] }`. The graph is a tree, so no visited set.
 *   After each step: one save of position + scope.
 * - Each step gets a `run_steps` row, written `running` before the step runs; `input` is the config
 *   with merge tags unrendered, `output` the declared outputs only.
 * - Resume: a step whose row is already finished is never run again; its stored result is applied.
 *   A row still `running` was interrupted: it fails ("Interrupted.") and its error policy applies.
 * - Flow: an action that ran or skipped → its children; a condition → `then` / `otherwise` by its
 *   result, or nothing when skipped. Failed + stop → the run fails; failed + continue → like skipped.
 * - Wait → the run waits, and a job wakes it at that time; the step completes on resume. A waiting
 *   row with no saved `wait_until` (crash before the run was saved) fails as interrupted.
 * - A slice stops after `SLICE_SECONDS` or `MEMORY_SHARE` of the memory limit and queues the next one.
 *
 * Nothing in PHP variables survives across steps: outputs go into the Scope, which is saved.
 */
final class Executor {

    public const SLICE_SECONDS = 20;
    public const MEMORY_SHARE  = 0.8;

    /** Step result → `run_steps` status. */
    private const STATUS_OF = [
        StepResult::OK      => RunStepStore::STATUS_COMPLETED,
        StepResult::SKIPPED => RunStepStore::STATUS_SKIPPED,
        StepResult::FAILED  => RunStepStore::STATUS_FAILED,
        StepResult::WAIT    => RunStepStore::STATUS_WAITING,
    ];

    /** @var RunRepository */
    private $runs;

    /** @var GraphSource */
    private $graphs;

    /** @var Queue */
    private $queue;

    /** @var Clock */
    private $clock;

    /** @var callable fn( string $id ): ?Step */
    private $steps;

    /** @var callable fn(): RefResolver */
    private $resolver;

    /**
     * @param RunRepository $runs     Runs and step rows.
     * @param GraphSource   $graphs   Pinned version graphs.
     * @param Queue         $queue    Wakes the run for its next slice or after a wait.
     * @param Clock         $clock    This slice's time and memory.
     * @param callable|null $steps    Step by id; null = `StepRegistry::get()`.
     * @param callable|null $resolver New RefResolver for the slice; null = the WooCommerce one.
     */
    public function __construct( RunRepository $runs, GraphSource $graphs, Queue $queue, Clock $clock, ?callable $steps = null, ?callable $resolver = null ) {
        $this->runs     = $runs;
        $this->graphs   = $graphs;
        $this->queue    = $queue;
        $this->clock    = $clock;
        $this->steps    = $steps ?? [ StepRegistry::class, 'get' ];
        $this->resolver = $resolver ?? fn() => new RefResolver();
    }

    /**
     * Runs steps until the run ends, waits, or the slice is used up. Never throws.
     *
     * @param array $run Run row, already claimed (status `running`).
     * @return string The run's status afterwards.
     */
    public function run( array $run ): string {
        $id = (int) $run['id'];
        try {
            return $this->slice( $run );
        } catch ( \Throwable $e ) {
            Logger::error( "Run {$id} failed: " . $e->getMessage(), [ 'run_id' => $id ] );
            $this->runs->save_run(
                $id,
                [
                    'status' => RunStore::STATUS_FAILED,
                    'error'  => $e->getMessage(),
                ]
            );
            return RunStore::STATUS_FAILED;
        }
    }

    /**
     * Step outputs' own display texts (`outputs()` `display`), for `Scope::set_output_display()`.
     *
     * @param array[]  $nodes Graph nodes by id.
     * @param callable $steps fn( string $id ): ?Step.
     */
    public static function output_display( array $nodes, callable $steps ): callable {
        return function ( string $step_id, string $key, array $outputs, bool $html ) use ( $nodes, $steps ): ?string {
            $id      = (string) ( $nodes[ $step_id ]['data']['block'] ?? '' );
            $step    = '' === $id ? null : $steps( $id );
            $display = $step instanceof Step ? ( $step->outputs()[ $key ]['display'] ?? null ) : null;
            return $display ? (string) $display( $outputs, $html ) : null;
        };
    }

    private function slice( array $run ): string {
        $id    = (int) $run['id'];
        $graph = $this->graphs->graph( (int) $run['version_id'] );
        if ( ! $graph || ! is_array( $graph['nodes'] ?? null ) ) {
            return $this->fail( $id, null, null, __( 'The workflow version this run started on no longer exists.', 'yaymatik' ) );
        }
        $nodes = array_column( $graph['nodes'], null, 'id' );
        $edges = is_array( $graph['edges'] ?? null ) ? $graph['edges'] : [];
        $scope = Scope::from_array( is_array( $run['scope'] ?? null ) ? $run['scope'] : [], ( $this->resolver )() );
        $scope->set_output_display( self::output_display( $nodes, $this->steps ) );
        $queue = is_array( $run['position']['queue'] ?? null )
            ? array_values( array_map( 'strval', $run['position']['queue'] ) )
            : self::children( $edges, self::trigger_id( $graph['nodes'] ) );

        $rows = [];
        foreach ( $this->runs->steps( $id ) as $row ) {
            $rows[ $row['step_id'] ] = $row;
        }
        $can_slice = $this->queue->available();

        while ( $queue ) {
            $step_id = $queue[0];
            $node    = $nodes[ $step_id ] ?? null;
            $step    = $node ? ( $this->steps )( (string) ( $node['data']['block'] ?? '' ) ) : null;
            $step    = $step instanceof Step && Step::KIND_TRIGGER !== $step->kind() ? $step : null;
            $outcome = $this->outcome( $run, $step_id, $node, $step, $rows[ $step_id ] ?? null, $scope );

            if ( RunStepStore::STATUS_WAITING === $outcome['status'] ) {
                return $this->wait( $id, $queue, $scope, $outcome );
            }
            if ( RunStepStore::STATUS_FAILED === $outcome['status'] && ( ! $step || Step::ON_ERROR_CONTINUE !== $step->on_error() ) ) {
                return $this->fail( $id, $queue, $scope, (string) $outcome['error'] );
            }

            array_shift( $queue );
            if ( RunStepStore::STATUS_COMPLETED === $outcome['status'] ) {
                $scope->set_step_outputs( $step_id, $outcome['output'] );
                $queue = array_merge( $queue, self::children( $edges, $step_id, $step instanceof Condition ? self::branch( $outcome['output'] ) : null ) );
            } elseif ( $step && ! $step instanceof Condition ) {
                // Skipped, or failed with `continue`: an action's children still run; a condition's path ends.
                $queue = array_merge( $queue, self::children( $edges, $step_id ) );
            }

            $fields = [
                'position'   => [ 'queue' => $queue ],
                'scope'      => $scope->to_array(),
                'wait_until' => null,
            ];
            $yield  = $queue && $can_slice && ( $this->clock->elapsed() >= self::SLICE_SECONDS || $this->clock->memory() >= self::MEMORY_SHARE );
            if ( $yield ) {
                $fields['status'] = RunStore::STATUS_QUEUED;
            }
            $this->runs->save_run( $id, $fields );
            if ( $yield ) {
                if ( ! $this->queue->enqueue( $id ) ) {
                    Logger::error( "Run {$id}: could not queue its next slice.", [ 'run_id' => $id ] );
                }
                return RunStore::STATUS_QUEUED;
            }
        }//end while

        $this->runs->save_run(
            $id,
            [
                'status'     => RunStore::STATUS_COMPLETED,
                'position'   => [ 'queue' => [] ],
                'scope'      => $scope->to_array(),
                'wait_until' => null,
            ]
        );
        return RunStore::STATUS_COMPLETED;
    }

    /**
     * The step's result as `status`, `output`, `error`, `until`: from its stored row when it already
     * ran, otherwise by running it now (row written before and after).
     *
     * @param array      $run     Run row (`id`, `wait_until`).
     * @param string     $step_id Step id.
     * @param array|null $node    Graph node, null when missing.
     * @param Step|null  $step    Its step, null when unknown or not runnable.
     * @param array|null $row     Its stored row from an earlier slice.
     * @param Scope      $scope   Run scope.
     */
    private function outcome( array $run, string $step_id, ?array $node, ?Step $step, ?array $row, Scope $scope ): array {
        $run_id = (int) $run['id'];
        if ( $row ) {
            switch ( $row['status'] ) {
                case RunStepStore::STATUS_RUNNING:
                    $error = __( 'Interrupted.', 'yaymatik' );
                    $this->runs->finish_step( (int) $row['id'], RunStepStore::STATUS_FAILED, [], $error );
                    return self::result( RunStepStore::STATUS_FAILED, [], $error );
                case RunStepStore::STATUS_WAITING:
                    $until = empty( $run['wait_until'] ) ? 0 : (int) strtotime( $run['wait_until'] . ' UTC' );
                    if ( ! $until ) {
                        // The run was never saved as waiting (crash right after the step): its time is unknown.
                        $error = __( 'Interrupted.', 'yaymatik' );
                        $this->runs->finish_step( (int) $row['id'], RunStepStore::STATUS_FAILED, [], $error );
                        return self::result( RunStepStore::STATUS_FAILED, [], $error );
                    }
                    if ( $until > $this->clock->now() ) {
                        // Woken early: wait again until the same time.
                        $outcome        = self::result( RunStepStore::STATUS_WAITING, (array) $row['output'], null, $until );
                        $outcome['row'] = (int) $row['id'];
                        return $outcome;
                    }
                    // Woken after its wait: the step is done.
                    $this->runs->finish_step( (int) $row['id'], RunStepStore::STATUS_COMPLETED, (array) $row['output'] );
                    return self::result( RunStepStore::STATUS_COMPLETED, (array) $row['output'] );
                default:
                    return self::result( (string) $row['status'], (array) $row['output'], $row['error'] );
            }//end switch
        }//end if

        // A sanitize() that throws still gets a row (with the raw config), so run history names the step.
        $config = is_array( $node['data']['config'] ?? null ) ? $node['data']['config'] : [];
        $broken = null;
        try {
            $config = $step ? $step->sanitize( $config ) : $config;
        } catch ( \Throwable $e ) {
            $broken = StepResult::failed( $e->getMessage() );
        }
        $row_id = $this->runs->start_step( $run_id, $step_id, $config );
        if ( ! $row_id ) {
            throw new \RuntimeException( __( 'Could not save the step result.', 'yaymatik' ) );
        }

        $scope->set_current_step( $run_id, $step_id );
        $result = $broken ?? self::run_step( $node, $step, $config, $scope );
        $status = self::STATUS_OF[ $result->status() ];
        $output = $result->is_ok() || $result->is_wait() ? array_intersect_key( $result->outputs(), $step ? $step->outputs() : [] ) : [];
        $error  = $result->is_skipped() || $result->is_failed() ? $result->message() : null;
        $this->runs->finish_step( $row_id, $status, $output, $error );
        $outcome        = self::result( $status, $output, $error, $result->until() );
        $outcome['row'] = $row_id;
        return $outcome;
    }

    /**
     * Runs a step. Never throws: an unknown or invalid step fails, a deleted entity skips it, any
     * other error fails it.
     *
     * @param array|null $node   Graph node.
     * @param Step|null  $step   Step, null when unknown.
     * @param array      $config Sanitized config.
     * @param Scope      $scope  Run scope.
     */
    private static function run_step( ?array $node, ?Step $step, array $config, Scope $scope ): StepResult {
        if ( ! $step ) {
            /* translators: %s: step type id */
            return StepResult::failed( sprintf( __( 'Step type "%s" is not available.', 'yaymatik' ), (string) ( $node['data']['block'] ?? '' ) ) );
        }
        try {
            $valid = $step->validate( $config );
            if ( true !== $valid ) {
                /* translators: %s: what is wrong with the step's settings */
                return StepResult::failed( sprintf( __( 'Invalid settings: %s', 'yaymatik' ), is_wp_error( $valid ) ? $valid->get_error_message() : '' ) );
            }
            return $step->run( $scope, $config );
        } catch ( GoneEntity $gone ) {
            return StepResult::skipped( self::gone_message( $gone ) );
        } catch ( \Throwable $e ) {
            return StepResult::failed( $e->getMessage() );
        }
    }

    /**
     * "Order no longer exists." for a deleted entity.
     *
     * @param GoneEntity $gone What couldn't be loaded.
     */
    public static function gone_message( GoneEntity $gone ): string {
        $entity = DataRegistry::entities()[ $gone->ref()->type() ] ?? null;
        /* translators: %s: what was deleted, e.g. "Order" */
        return sprintf( __( '%s no longer exists.', 'yaymatik' ), $entity ? $entity->label() : $gone->ref()->type() );
    }

    /**
     * Pauses the run at the waiting step (still first in the queue) and wakes it at the step's
     * `until`. Without a queue nothing could wake it, so the step and the run fail.
     *
     * @param int      $id      Run id.
     * @param string[] $queue   Cursor, the waiting step first.
     * @param Scope    $scope   Run scope.
     * @param array    $outcome The step's outcome, with `until` and its `row` id.
     */
    private function wait( int $id, array $queue, Scope $scope, array $outcome ): string {
        $until = (int) $outcome['until'];
        if ( ! $this->queue->available() ) {
            $error = __( 'This run can\'t wait: Action Scheduler is not available.', 'yaymatik' );
            $this->runs->finish_step( (int) $outcome['row'], RunStepStore::STATUS_FAILED, [], $error );
            return $this->fail( $id, $queue, $scope, $error );
        }
        $this->runs->save_run(
            $id,
            [
                'status'     => RunStore::STATUS_WAITING,
                'wait_until' => gmdate( 'Y-m-d H:i:s', $until ),
                'position'   => [ 'queue' => $queue ],
                'scope'      => $scope->to_array(),
            ]
        );
        if ( ! $this->queue->enqueue( $id, $until ) ) {
            Logger::error( "Run {$id}: could not queue the end of its wait.", [ 'run_id' => $id ] );
        }
        return RunStore::STATUS_WAITING;
    }

    /**
     * Ends the run as failed; later steps don't run.
     *
     * @param int           $id    Run id.
     * @param string[]|null $queue Cursor, the failed step first; null keeps the stored one.
     * @param Scope|null    $scope Run scope; null keeps the stored one.
     * @param string        $error Why.
     */
    private function fail( int $id, ?array $queue, ?Scope $scope, string $error ): string {
        $fields = [
            'status'     => RunStore::STATUS_FAILED,
            'error'      => $error,
            'wait_until' => null,
        ];
        if ( null !== $queue && $scope ) {
            $fields['position'] = [ 'queue' => $queue ];
            $fields['scope']    = $scope->to_array();
        }
        $this->runs->save_run( $id, $fields );
        return RunStore::STATUS_FAILED;
    }

    /**
     * @param string      $status Step status.
     * @param array       $output Declared outputs.
     * @param string|null $error  Reason.
     * @param int         $until  Unix time, when waiting.
     */
    private static function result( string $status, array $output, ?string $error = null, int $until = 0 ): array {
        return [
            'status' => $status,
            'output' => $output,
            'error'  => $error,
            'until'  => $until,
            'row'    => 0,
        ];
    }

    /**
     * A condition's branch handle from its outputs.
     *
     * @param array $output Condition outputs.
     */
    private static function branch( array $output ): string {
        return 'yes' === ( $output['result'] ?? '' ) ? 'then' : 'otherwise';
    }

    /**
     * @param array[] $nodes Graph nodes.
     */
    private static function trigger_id( array $nodes ): string {
        foreach ( $nodes as $node ) {
            if ( Step::KIND_TRIGGER === ( $node['kind'] ?? '' ) ) {
                return (string) $node['id'];
            }
        }
        return '';
    }

    /**
     * Step ids under a step, on one branch handle (null = no handle).
     *
     * @param array[]     $edges  Graph edges.
     * @param string      $source Parent step id.
     * @param string|null $branch Handle.
     * @return string[]
     */
    private static function children( array $edges, string $source, ?string $branch = null ): array {
        $children = [];
        foreach ( $edges as $edge ) {
            if ( ( $edge['source'] ?? '' ) === $source && ( $edge['sourceHandle'] ?? null ) === $branch ) {
                $children[] = (string) $edge['target'];
            }
        }
        return $children;
    }
}
