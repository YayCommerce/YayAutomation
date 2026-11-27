<?php
namespace YayMatik\Workflow\Core\Runtime;

use YayMatik\Utils\Logger;
use YayMatik\Workflow\Conditions\Criteria;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Data\GoneEntity;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Definition\GraphSchema;
use YayMatik\Workflow\Core\Definition\ProUsage;
use YayMatik\Workflow\Core\Email\DeliveryStatus;
use YayMatik\Workflow\Core\Steps\Condition;
use YayMatik\Workflow\Core\Steps\Step;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Core\Steps\StepResult;
use YayMatik\Workflow\Core\Steps\Trigger;
use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\DataItems\CustomerData;
use YayMatik\Workflow\Extension\StepIndex;

defined( 'ABSPATH' ) || exit;

/** Runs one slice of a claimed run over its pinned version's graph, saving position and scope after each step. */
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

    /** @var Workflows */
    private $workflows;

    /** @var Clock */
    private $clock;

    /** @var callable fn( string $id ): ?Step */
    private $steps;

    /** @var callable fn(): RefResolver */
    private $resolver;

    /**
     * @param RunRepository $runs
     * @param GraphSource   $graphs
     * @param Queue         $queue
     * @param Workflows     $workflows
     * @param Clock         $clock
     * @param callable|null $steps     Null = `StepRegistry::get()`.
     * @param callable|null $resolver  Null = the WooCommerce one.
     */
    public function __construct( RunRepository $runs, GraphSource $graphs, Queue $queue, Workflows $workflows, Clock $clock, ?callable $steps = null, ?callable $resolver = null ) {
        $this->runs      = $runs;
        $this->graphs    = $graphs;
        $this->queue     = $queue;
        $this->workflows = $workflows;
        $this->clock     = $clock;
        $this->steps     = $steps ?? [ StepRegistry::class, 'get' ];
        $this->resolver  = $resolver ?? fn() => new RefResolver();
    }

    /**
     * Runs steps until the run ends, waits, or the slice is used up. Never throws.
     *
     * @param array $run Claimed (`running`).
     * @return string The run's status afterwards.
     */
    public function run( array $run ): string {
        $id = (int) $run['id'];
        try {
            return $this->slice( $run );
        } catch ( \Throwable $e ) {
            Logger::error( "Run {$id} failed: " . self::message( $e ), [ 'run_id' => $id ] );
            $this->runs->save_run(
                $id,
                [
                    'status' => RunStore::STATUS_FAILED,
                    'error'  => self::message( $e ),
                ]
            );
            return RunStore::STATUS_FAILED;
        }
    }

    /**
     * Step outputs' own display texts (`outputs()` `display`), for `Scope::set_output_display()`.
     *
     * @param array[]  $nodes By id.
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

    /**
     * Why the run's trigger stops it now, or null to go on.
     *
     * @param array    $run
     * @param callable $steps fn( string $id ): ?Step.
     */
    public static function stop_reason( array $run, callable $steps ): ?string {
        $trigger = $steps( (string) ( $run['trigger_id'] ?? '' ) );
        return $trigger instanceof Trigger ? $trigger->stop_reason( (string) ( $run['subject'] ?? '' ) ) : null;
    }

    private function slice( array $run ): string {
        $id    = (int) $run['id'];
        $graph = $this->graphs->graph( (int) $run['version_id'] );
        if ( ! $graph || ! is_array( $graph['nodes'] ?? null ) ) {
            return $this->fail( $id, null, null, __( 'The workflow version this run started on no longer exists.', 'yaymatik' ) );
        }
        $nodes   = GraphSchema::by_id( $graph['nodes'] );
        $edges   = is_array( $graph['edges'] ?? null ) ? $graph['edges'] : [];
        $scope   = Scope::from_array( is_array( $run['scope'] ?? null ) ? $run['scope'] : [], ( $this->resolver )() );
        $trigger = ( $this->steps )( (string) ( $run['trigger_id'] ?? '' ) );
        $scope->set_trigger( $trigger instanceof Trigger ? $trigger : null );
        $scope->set_output_display( self::output_display( $nodes, $this->steps ) );
        // The cursor is the pending step ids; the graph is a tree, so no visited set.
        $queue = is_array( $run['position']['queue'] ?? null )
            ? array_values( array_map( 'strval', $run['position']['queue'] ) )
            : self::children( $edges, (string) ( GraphSchema::trigger( $graph['nodes'] )['id'] ?? '' ) );

        $rows = [];
        foreach ( $this->runs->steps( $id ) as $row ) {
            $rows[ $row['step_id'] ] = $row;
        }
        $can_slice = $this->queue->available();

        while ( $queue ) {
            if ( ! $this->workflows->is_active( (int) $run['workflow_id'] ) ) {
                return $this->cancel( $id, $queue, $scope, Cancellation::turned_off() );
            }
            $reason = self::stop_reason( $run, $this->steps );
            if ( null !== $reason ) {
                return $this->cancel( $id, $queue, $scope, $reason );
            }
            $step_id = $queue[0];
            $node    = $nodes[ $step_id ] ?? null;
            $step    = $node ? ( $this->steps )( (string) ( $node['data']['block'] ?? '' ) ) : null;
            $step    = $step instanceof Step && Step::KIND_TRIGGER !== $step->kind() ? $step : null;
            // A run that started on YayMatik Pro stops before a step this site can't run as written.
            if ( $node && ! isset( $rows[ $step_id ] ) && ProUsage::node( $node ) ) {
                return $this->cancel( $id, $queue, $scope, __( 'Needs YayMatik Pro.', 'yaymatik' ) );
            }
            $outcome = $this->outcome( $run, $step_id, $node, $step, $rows[ $step_id ] ?? null, $scope );

            if ( RunStepStore::STATUS_WAITING === $outcome['status'] ) {
                return $this->wait( $run, $queue, $scope, $outcome );
            }
            if ( RunStepStore::STATUS_FAILED === $outcome['status'] && ( ! $step || Step::ON_ERROR_CONTINUE !== $step->on_error() ) ) {
                return $this->fail( $id, $queue, $scope, (string) $outcome['error'] );
            }

            array_shift( $queue );
            if ( RunStepStore::STATUS_COMPLETED === $outcome['status'] ) {
                $scope->set_step_outputs( $step_id, $outcome['output'] );
                $queue = array_merge( $queue, self::children( $edges, $step_id, $step instanceof Condition ? self::branch( $outcome['output'] ) : null ) );
            } elseif ( $step ? ! $step instanceof Condition : null !== StepIndex::inactive( (string) ( $node['data']['block'] ?? '' ) ) ) {
                // Skipped, or failed with `continue`: an action's children still run, also after a step of a plugin
                // that's off; a condition's path ends.
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
        }

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
     * The step's `status`, `output`, `error`, `until`, from its stored row or by running it.
     *
     * @param array      $run
     * @param string     $step_id
     * @param array|null $node
     * @param Step|null  $step    Null when unknown or not runnable.
     * @param array|null $row     From an earlier slice.
     * @param Scope      $scope
     */
    private function outcome( array $run, string $step_id, ?array $node, ?Step $step, ?array $row, Scope $scope ): array {
        $run_id = (int) $run['id'];
        // A step with a row already ran: its stored result applies, so a finished step never runs twice.
        if ( $row ) {
            // An email's delivery status is run detail, not a value for later steps.
            $output = array_diff_key( (array) $row['output'], [ 'delivery' => true ] );
            switch ( $row['status'] ) {
                case RunStepStore::STATUS_RUNNING:
                    // Still running from an earlier slice: it was interrupted, and its error policy applies.
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
                        $outcome        = self::result( RunStepStore::STATUS_WAITING, $output, null, $until );
                        $outcome['row'] = (int) $row['id'];
                        return $outcome;
                    }
                    // Woken after its wait: the step is done.
                    $this->runs->finish_step( (int) $row['id'], RunStepStore::STATUS_COMPLETED, (array) $row['output'] );
                    return self::result( RunStepStore::STATUS_COMPLETED, $output );
                default:
                    return self::result( (string) $row['status'], $output, $row['error'] );
            }
        }

        // A sanitize() that throws still gets a row (with the raw config), so run history names the step.
        $config = is_array( $node['data']['config'] ?? null ) ? $node['data']['config'] : [];
        $broken = null;
        try {
            $config = $step ? $step->sanitize( $config ) : $config;
        } catch ( \Throwable $e ) {
            $broken = StepResult::failed( self::message( $e ) );
        }
        $row_id = $this->runs->start_step( $run_id, $step_id, $config );
        if ( ! $row_id ) {
            throw new \RuntimeException( esc_html__( 'Could not save the step result.', 'yaymatik' ) );
        }

        $scope->set_current_step( $run_id, $step_id, $row_id );
        DeliveryStatus::start( $row_id );
        $result   = $broken ?? self::run_step( $node, $step, $config, $scope );
        $delivery = DeliveryStatus::finish( $row_id );
        $status   = self::STATUS_OF[ $result->status() ];
        $output   = $result->is_ok() || $result->is_wait() ? array_intersect_key( $result->outputs(), $step ? $step->outputs() : [] ) : [];
        $error    = $result->is_skipped() || $result->is_failed() ? $result->message() : null;
        $this->runs->finish_step( $row_id, $status, $delivery ? $output + [ 'delivery' => $delivery ] : $output, $error );
        $outcome        = self::result( $status, $output, $error, $result->until() );
        $outcome['row'] = $row_id;
        return $outcome;
    }

    /**
     * Never throws: an unknown or invalid step fails, a deleted entity skips it, any other error fails it.
     *
     * @param array|null $node
     * @param Step|null  $step
     * @param array      $config
     * @param Scope      $scope
     */
    private static function run_step( ?array $node, ?Step $step, array $config, Scope $scope ): StepResult {
        if ( ! $step ) {
            $block    = (string) ( $node['data']['block'] ?? '' );
            $inactive = StepIndex::inactive( $block );
            if ( $inactive ) {
                // The plugin is off: the run goes on without this step (docs/extension-api.md §10).
                /* translators: %s: plugin name, e.g. "YayMail" */
                return StepResult::skipped( sprintf( __( '%s isn’t active.', 'yaymatik' ), $inactive['plugin'] ) );
            }
            /* translators: %s: step type id */
            return StepResult::failed( sprintf( __( 'Step type "%s" is not available.', 'yaymatik' ), $block ) );
        }
        try {
            $off = $step instanceof Criteria ? $step->inactive_plugin( $config ) : null;
            if ( $off ) {
                // Its rule's data is gone with the plugin: the path ends here, as when the step's own plugin is off.
                /* translators: %s: plugin name, e.g. "YayPricing" */
                return StepResult::skipped( sprintf( __( '%s isn’t active.', 'yaymatik' ), $off ) );
            }
            $valid = $step->validate( $config );
            if ( true === $valid ) {
                $valid = $step->context_error( $config, self::available( $scope ), $scope->trigger() ) ?? true;
            }
            if ( true !== $valid ) {
                /* translators: %s: what is wrong with the step's settings */
                return StepResult::failed( sprintf( __( 'Invalid settings: %s', 'yaymatik' ), is_wp_error( $valid ) ? $valid->get_error_message() : '' ) );
            }
            return $step->run( $scope, $config );
        } catch ( GoneEntity $gone ) {
            return StepResult::skipped( self::gone_message( $gone ) );
        } catch ( \Throwable $e ) {
            return StepResult::failed( self::message( $e ) );
        }
    }

    /**
     * Data item ids the run's trigger holds a ref for, plus the ones always there.
     *
     * @param Scope $scope
     */
    private static function available( Scope $scope ): array {
        $refs = array_filter( (array) $scope->get( 'trigger' ), fn( $value ) => $value instanceof Ref );
        return array_values( array_unique( array_merge( array_keys( $refs ), DataRegistry::ALWAYS ) ) );
    }

    /**
     * An exception's message as plain text: messages are escaped where they are thrown, and the run history
     * escapes what it shows.
     *
     * @param \Throwable $e
     */
    private static function message( \Throwable $e ): string {
        return htmlspecialchars_decode( $e->getMessage(), ENT_QUOTES );
    }

    /**
     * "Order no longer exists."; an account is a person, so "The customer was deleted."
     *
     * @param GoneEntity $gone
     */
    public static function gone_message( GoneEntity $gone ): string {
        if ( CustomerData::ID === $gone->ref()->type() && 0 === strpos( $gone->ref()->id(), 'user:' ) ) {
            return __( 'The customer was deleted.', 'yaymatik' );
        }
        $entity = DataRegistry::entities()[ $gone->ref()->type() ] ?? null;
        /* translators: %s: what was deleted, e.g. "Order" or order:12 */
        return sprintf( __( '%s no longer exists.', 'yaymatik' ), $entity ? $entity->label() : $gone->ref()->type() );
    }

    /**
     * Pauses the run until the step's `until`. Without a queue nothing could wake it, so it fails.
     *
     * @param array    $run
     * @param string[] $queue   The waiting step first.
     * @param Scope    $scope
     * @param array    $outcome
     */
    private function wait( array $run, array $queue, Scope $scope, array $outcome ): string {
        $id    = (int) $run['id'];
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
        // Turned off or stopped while the step ran: Cancellation only finds runs already saved as waiting.
        if ( ! $this->workflows->is_active( (int) $run['workflow_id'] ) ) {
            return $this->cancel( $id, $queue, $scope, Cancellation::turned_off() );
        }
        $reason = self::stop_reason( $run, $this->steps );
        if ( null !== $reason ) {
            return $this->cancel( $id, $queue, $scope, $reason );
        }
        if ( ! $this->queue->enqueue( $id, $until ) ) {
            Logger::error( "Run {$id}: could not queue the end of its wait.", [ 'run_id' => $id ] );
        }
        return RunStore::STATUS_WAITING;
    }

    /**
     * @param int           $id
     * @param string[]|null $queue Null keeps the stored one.
     * @param Scope|null    $scope Null keeps the stored one.
     * @param string        $error
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
     * Stops the run before its next step.
     *
     * @param int      $id
     * @param string[] $queue
     * @param Scope    $scope
     * @param string   $reason
     */
    private function cancel( int $id, array $queue, Scope $scope, string $reason ): string {
        $this->runs->save_run(
            $id,
            [
                'status'     => RunStore::STATUS_CANCELLED,
                'error'      => $reason,
                'wait_until' => null,
                'position'   => [ 'queue' => $queue ],
                'scope'      => $scope->to_array(),
            ]
        );
        $this->runs->close_steps( $id, RunStepStore::STATUS_CANCELLED, $reason );
        return RunStore::STATUS_CANCELLED;
    }

    private static function result( string $status, array $output, ?string $error = null, int $until = 0 ): array {
        return [
            'status' => $status,
            'output' => $output,
            'error'  => $error,
            'until'  => $until,
            'row'    => 0,
        ];
    }

    private static function branch( array $output ): string {
        return 'yes' === ( $output['result'] ?? '' ) ? 'then' : 'otherwise';
    }

    /**
     * @param array[]     $edges
     * @param string      $source
     * @param string|null $branch Null = no handle.
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
