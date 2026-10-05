<?php
namespace YayAutomation\Engine\Workflows;

use YayAutomation\Engine\Blocks\Core\Action;
use YayAutomation\Engine\Blocks\Core\BlockRegistry;
use YayAutomation\Engine\Blocks\Core\Condition;
use YayAutomation\Engine\Blocks\Core\Trigger;
use YayAutomation\Utils\Logger;
use YayAutomation\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Runs active workflows, in the background:
 * 1. Triggers report events while a request runs (`collect()`).
 * 2. At shutdown, each event's matching workflows are queued as Action Scheduler jobs (`flush()`).
 * 3. A job rebuilds the context and walks the workflow's graph (`run_job()`).
 *
 * Loop guard: a job carries the chain of workflows that led to it. Events a run causes on its own
 * subject (e.g. the same order) never queue a workflow already in that chain, so neither a workflow
 * re-triggering itself nor two workflows triggering each other can loop. Nothing here may throw
 * into the host request: failures are logged.
 */
class Runner {

    use SingletonTrait;

    public const JOB = 'yay_automation_run_workflow';

    private const GROUP = 'yay-automation';

    /** @var array[] Events of this request: key => [ trigger, args, chain (workflow ids) ]. */
    private $events = [];

    /** @var array[] Workflows running now: subject => [ workflow id => chain incl. itself ]. */
    private $running = [];

    protected function __construct() {
        foreach ( BlockRegistry::triggers() as $trigger ) {
            $trigger->listen(
                function ( array $args ) use ( $trigger ) {
                    $this->collect( $trigger, $args );
                }
            );
        }
        add_action( self::JOB, [ $this, 'run_job' ], 10, 4 );
        add_action( 'shutdown', [ $this, 'flush' ] );
    }

    /**
     * Remembers an event until the end of the request. The same event reported twice counts once.
     *
     * @param Trigger $trigger Reporting trigger.
     * @param array   $args    Event args.
     */
    private function collect( Trigger $trigger, array $args ): void {
        $key = $trigger->id() . '|' . wp_json_encode( $args );
        if ( isset( $this->events[ $key ] ) ) {
            return;
        }
        // Decided now: by shutdown the run that caused the event has finished.
        $running              = $this->running[ $trigger->subject( $args ) ] ?? [];
        $this->events[ $key ] = [
            'trigger' => $trigger,
            'args'    => $args,
            'chain'   => array_values( array_unique( array_merge( [], ...array_values( $running ) ) ) ),
        ];
    }

    /** Queues one job per matching active workflow and event. */
    public function flush(): void {
        $events       = $this->events;
        $this->events = [];

        foreach ( $events as $event ) {
            $trigger = $event['trigger'];
            try {
                // Cheapest check first: most events have no workflow, so skip claiming (a meta write) and loading.
                $workflows = WorkflowRepository::active_for_trigger( $trigger->id() );
                if ( ! $workflows || ! $trigger->claim( $event['args'] ) ) {
                    continue;
                }
                $context = $trigger->context( $event['args'] );
                if ( ! $context ) {
                    continue;
                }
                foreach ( $workflows as $workflow ) {
                    $node = self::trigger_node( $workflow );
                    if ( ! $node || in_array( (int) $workflow['id'], $event['chain'], true )
                        || true !== $trigger->validate( $node['data']['config'] )
                        || ! $trigger->matches( $context, $node['data']['config'] ) ) {
                        continue;
                    }
                    $this->enqueue( (int) $workflow['id'], $trigger->id(), $event['args'], $event['chain'] );
                }
            } catch ( \Throwable $e ) {
                Logger::error( "Could not queue workflows for {$trigger->id()}: " . $e->getMessage(), [ 'args' => $event['args'] ] );
            }
        }
    }

    /**
     * Action Scheduler job. Skips the run when the workflow was turned off or its trigger changed
     * since it was queued.
     *
     * @param int    $workflow_id Workflow post id.
     * @param string $trigger_id  Trigger block id the event came from.
     * @param array  $args        Event args.
     * @param array  $chain       Ids of the workflows whose runs led to this job.
     */
    public function run_job( $workflow_id, $trigger_id, $args, $chain = [] ): void {
        $workflow = WorkflowRepository::find( (int) $workflow_id );
        $trigger  = BlockRegistry::get( (string) $trigger_id );
        $node     = $workflow ? self::trigger_node( $workflow ) : null;
        if ( ! $workflow || 'active' !== $workflow['status'] || ! $trigger instanceof Trigger || ! $node || $node['data']['block'] !== $trigger->id() ) {
            return;
        }

        $args    = is_array( $args ) ? $args : [];
        $subject = $trigger->subject( $args );
        try {
            $context = $trigger->context( $args );
            if ( ! $context ) {
                return;
            }
            $this->running[ $subject ][ $workflow['id'] ] = array_merge( array_map( 'intval', (array) $chain ), [ (int) $workflow['id'] ] );
            $this->run( $workflow, $context, $subject );
        } catch ( \Throwable $e ) {
            $this->log( $workflow, $subject, $e->getMessage() );
        } finally {
            unset( $this->running[ $subject ][ $workflow['id'] ] );
        }
    }

    private function enqueue( int $workflow_id, string $trigger_id, array $args, array $chain ): void {
        $job_args = [ $workflow_id, $trigger_id, $args, $chain ];
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( self::JOB, $job_args, self::GROUP );
        } else {
            $this->run_job( ...$job_args );
        }
    }

    /**
     * Walks the graph from the trigger: actions run in order, conditions pick the `then` or `otherwise` edge.
     *
     * @param array  $workflow Stored workflow.
     * @param array  $context  Run context, see Block.
     * @param string $subject  For log lines.
     */
    private function run( array $workflow, array $context, string $subject ): void {
        $nodes   = array_column( $workflow['graph']['nodes'], null, 'id' );
        $edges   = $workflow['graph']['edges'];
        $queue   = self::children( $edges, self::trigger_node( $workflow )['id'] );
        $visited = [];

        while ( $queue ) {
            $id = array_shift( $queue );
            if ( isset( $visited[ $id ] ) || ! isset( $nodes[ $id ] ) ) {
                continue;
            }
            $visited[ $id ] = true;

            $config = $nodes[ $id ]['data']['config'];
            $block  = BlockRegistry::get( (string) $nodes[ $id ]['data']['block'] );
            if ( ! $block || true !== $block->validate( $config ) ) {
                // Saved active only when valid; a failure here means the stored data changed underneath us.
                $this->log( $workflow, $subject, "Skipped invalid step {$id}." );
                continue;
            }

            if ( $block instanceof Condition ) {
                $branch = $block->evaluate( $context, $config ) ? 'then' : 'otherwise';
                $queue  = array_merge( $queue, self::children( $edges, $id, $branch ) );
            } elseif ( $block instanceof Action ) {
                $result = $block->run( $context, $config );
                if ( is_wp_error( $result ) ) {
                    $this->log( $workflow, $subject, "Step {$id}: " . $result->get_error_message() );
                }
                $queue = array_merge( $queue, self::children( $edges, $id ) );
            }
        }//end while
    }

    private static function trigger_node( array $workflow ): ?array {
        foreach ( $workflow['graph']['nodes'] as $node ) {
            if ( 'trigger' === $node['kind'] ) {
                return $node;
            }
        }
        return null;
    }

    private static function children( array $edges, string $source, ?string $branch = null ): array {
        $children = [];
        foreach ( $edges as $edge ) {
            if ( $edge['source'] === $source && ( $edge['sourceHandle'] ?? null ) === $branch ) {
                $children[] = $edge['target'];
            }
        }
        return $children;
    }

    private function log( array $workflow, string $subject, string $message ): void {
        Logger::error(
            "Workflow {$workflow['id']} failed: {$message}",
            [
                'workflow_id' => $workflow['id'],
                'subject'     => $subject,
            ]
        );
    }
}
