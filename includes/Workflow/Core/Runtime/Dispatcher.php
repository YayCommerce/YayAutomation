<?php
namespace YayMatik\Workflow\Core\Runtime;

use YayMatik\Utils\Logger;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Core\Steps\Trigger;

defined( 'ABSPATH' ) || exit;

/** Collects trigger events during a request and starts their runs at shutdown; never throws into the host request. */
final class Dispatcher {

    // Within RATE_WINDOW seconds, LOOP_LIMIT counts runs started by other runs: a loop always has a
    // parent, so a bulk edit of hundreds of orders (plain events) never trips it.
    public const MAX_DEPTH   = 5;
    public const RATE_WINDOW = 60;
    public const LOOP_LIMIT  = 100;
    public const CEILING     = 1000;

    /** @var RunRepository */
    private $runs;

    /** @var Queue */
    private $queue;

    /** @var Workflows */
    private $workflows;

    /** @var callable fn( int $run_id ): void */
    private $run_inline;

    /** @var callable fn(): ?array, see `Worker::current()`. */
    private $current;

    /** @var array[] Key => [ trigger, args, parent run or null ]. */
    private $events = [];

    /** @var true[] Workflow ids the rate limit turned off in this request. */
    private $paused = [];

    /**
     * @param RunRepository $runs
     * @param Queue         $queue
     * @param Workflows     $workflows
     * @param callable      $run_inline Runs a run now, when there is no queue.
     * @param callable      $current
     */
    public function __construct( RunRepository $runs, Queue $queue, Workflows $workflows, callable $run_inline, callable $current ) {
        $this->runs       = $runs;
        $this->queue      = $queue;
        $this->workflows  = $workflows;
        $this->run_inline = $run_inline;
        $this->current    = $current;
    }

    /** Listens to every trigger and flushes at shutdown. */
    public function register(): void {
        foreach ( StepRegistry::triggers() as $trigger ) {
            $trigger->listen(
                function ( array $args ) use ( $trigger ) {
                    $this->collect( $trigger, $args );
                }
            );
        }
        add_action( 'shutdown', [ $this, 'flush' ] );
    }

    /**
     * Remembers an event until the end of the request; the same event twice counts once.
     *
     * @param Trigger $trigger
     * @param array   $args
     */
    public function collect( Trigger $trigger, array $args ): void {
        try {
            $key = $trigger->id() . '|' . wp_json_encode( $args );
            if ( isset( $this->events[ $key ] ) ) {
                return;
            }
            // Decided now: by shutdown the run that caused the event has finished.
            $this->events[ $key ] = [
                'trigger' => $trigger,
                'args'    => $args,
                'parent'  => ( $this->current )(),
            ];
        } catch ( \Throwable $e ) {
            Logger::error( "Could not record an event for {$trigger->id()}: " . $e->getMessage(), [ 'args' => $args ] );
        }
    }

    /** Starts the collected events' runs, including events raised by runs that went inline. */
    public function flush(): void {
        while ( $this->events ) {
            $events       = $this->events;
            $this->events = [];
            foreach ( $events as $event ) {
                $this->dispatch( $event['trigger'], $event['args'], $event['parent'] );
            }
        }
    }

    /**
     * A queued run after the loop and rate checks, without claim, match or queue (dev scripts call it).
     *
     * @param array      $workflow
     * @param Trigger    $trigger
     * @param array      $args
     * @param array|null $parent_run
     * @param array|null $refs       `$trigger->resolved_refs( $args )` when already loaded.
     * @return int Run id; 0 when no run was started.
     */
    public function start_run( array $workflow, Trigger $trigger, array $args, ?array $parent_run = null, ?array $refs = null ): int {
        $workflow_id = (int) $workflow['id'];
        $context     = [
            'workflow_id' => $workflow_id,
            'subject'     => $trigger->subject( $args ),
        ];
        $depth       = $parent_run ? (int) $parent_run['depth'] + 1 : 0;
        if ( $depth > self::MAX_DEPTH ) {
            Logger::error( "Workflow {$workflow_id} not started: runs started each other more than " . self::MAX_DEPTH . ' levels deep.', $context );
            return 0;
        }
        if ( isset( $this->paused[ $workflow_id ] ) ) {
            return 0;
        }
        if ( $parent_run && $this->in_chain( $workflow_id, (int) $parent_run['id'] ) ) {
            // A workflow never restarts itself through the runs it caused.
            return 0;
        }
        $recent = $this->runs->recent_counts( $workflow_id, self::RATE_WINDOW );
        $reason = '';
        if ( $recent['chained'] >= self::LOOP_LIMIT ) {
            $reason = self::LOOP_LIMIT . ' runs started by other runs';
        } elseif ( $recent['all'] >= self::CEILING ) {
            $reason = self::CEILING . ' runs';
        }
        if ( $reason ) {
            $this->paused[ $workflow_id ] = true;
            Logger::error( "Workflow {$workflow_id} paused: {$reason} within " . self::RATE_WINDOW . ' seconds. Turning it off.', $context );
            $this->workflows->deactivate( $workflow_id );
            return 0;
        }
        $version_id = $this->workflows->live_version_id( $workflow_id );
        if ( ! $version_id ) {
            Logger::error( "Workflow {$workflow_id} not started: it has no saved version.", $context );
            return 0;
        }
        $refs = $refs ?? $trigger->resolved_refs( $args );
        if ( null === $refs ) {
            return 0;
        }

        return $this->runs->create_run(
            [
                'workflow_id'   => $workflow_id,
                'version_id'    => $version_id,
                'trigger_id'    => $trigger->id(),
                'subject'       => $context['subject'],
                // Refs and trigger-time facts only, never whole objects.
                'scope'         => ( new Scope( [ 'trigger' => $refs ], null, [] ) )->to_array(),
                'parent_run_id' => $parent_run ? (int) $parent_run['id'] : 0,
                'root_run_id'   => $parent_run ? (int) $parent_run['root_run_id'] : 0,
                'depth'         => $depth,
            ]
        );
    }

    private function dispatch( Trigger $trigger, array $args, ?array $parent_run ): void {
        try {
            // Cheapest check first: most events have no workflow, so skip claiming (a write) and loading.
            $workflows = $this->workflows->active_for_trigger( $trigger->id() );
            if ( ! $workflows || ! $trigger->claim( $args ) ) {
                return;
            }
            $context = $trigger->context( $args );
            $refs    = $context ? $trigger->resolved_refs( $args ) : null;
            if ( ! $context || null === $refs ) {
                return;
            }
            foreach ( $workflows as $workflow ) {
                $node = self::trigger_node( $workflow );
                if ( ! $node || $node['data']['block'] !== $trigger->id()
                    || true !== $trigger->validate( $node['data']['config'] )
                    || ! $trigger->matches( $context, $node['data']['config'] ) ) {
                    continue;
                }
                $run_id = $this->start_run( $workflow, $trigger, $args, $parent_run, $refs );
                if ( $run_id ) {
                    $this->queue_run( $run_id );
                }
            }
        } catch ( \Throwable $e ) {
            Logger::error( "Could not start workflows for {$trigger->id()}: " . $e->getMessage(), [ 'args' => $args ] );
        }
    }

    /**
     * Without a queue the run goes now; if queueing fails, it stays queued.
     *
     * @param int $run_id
     */
    private function queue_run( int $run_id ): void {
        if ( ! $this->queue->available() ) {
            ( $this->run_inline )( $run_id );
            return;
        }
        if ( ! $this->queue->enqueue( $run_id ) ) {
            Logger::error( "Run {$run_id}: could not queue it.", [ 'run_id' => $run_id ] );
        }
    }

    /**
     * Whether the workflow already ran in the chain of runs that led here.
     *
     * @param int $workflow_id
     * @param int $run_id      Parent run.
     */
    private function in_chain( int $workflow_id, int $run_id ): bool {
        for ( $hops = 0; $run_id > 0 && $hops <= self::MAX_DEPTH; $hops++ ) {
            $run = $this->runs->find_run( $run_id );
            if ( ! $run ) {
                return false;
            }
            if ( (int) $run['workflow_id'] === $workflow_id ) {
                return true;
            }
            $run_id = (int) $run['parent_run_id'];
        }
        return false;
    }

    private static function trigger_node( array $workflow ): ?array {
        foreach ( $workflow['graph']['nodes'] ?? [] as $node ) {
            if ( 'trigger' === ( $node['kind'] ?? '' ) ) {
                return $node;
            }
        }
        return null;
    }
}
