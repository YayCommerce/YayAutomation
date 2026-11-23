<?php
namespace YayMatik\Workflow\Core\Runtime;

use YayMatik\Utils\Logger;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Definition\GraphSchema;
use YayMatik\Workflow\Core\Definition\ProUsage;
use YayMatik\Workflow\Core\Steps\Trigger;
use YayMatik\Workflow\Core\Storage\RunStore;

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

    /** @var callable fn(): Trigger[] */
    private $triggers;

    /** @var true[] Trigger ids with a listener. */
    private $listening = [];

    /** @var array[] Trigger id => its active workflows, for one `flush()`. */
    private $active = [];

    /**
     * @param RunRepository $runs
     * @param Queue         $queue
     * @param Workflows     $workflows
     * @param callable      $run_inline Runs a run now, when there is no queue.
     * @param callable      $current
     * @param callable      $triggers Returns the registered triggers.
     */
    public function __construct( RunRepository $runs, Queue $queue, Workflows $workflows, callable $run_inline, callable $current, callable $triggers ) {
        $this->runs       = $runs;
        $this->queue      = $queue;
        $this->workflows  = $workflows;
        $this->run_inline = $run_inline;
        $this->current    = $current;
        $this->triggers   = $triggers;
    }

    /** Listens to the active triggers, and to those a change adds, and flushes at shutdown. */
    public function register(): void {
        add_action( 'yaymatik_active_triggers_changed', [ $this, 'listen_active' ] );
        $this->listen_active();
        add_action( 'shutdown', [ $this, 'flush' ] );
    }

    /** Adds the listeners still missing; all of them when the list can't be read. One stays until the process ends: `dispatch()` then finds no workflow. */
    public function listen_active(): void {
        $active = $this->workflows->active_trigger_ids();
        foreach ( ( $this->triggers )() as $trigger ) {
            $id = $trigger->id();
            if ( isset( $this->listening[ $id ] ) || ( null !== $active && ! in_array( $id, $active, true ) ) ) {
                continue;
            }
            $this->listening[ $id ] = true;
            $trigger->listen(
                function ( array $args ) use ( $trigger ) {
                    $this->collect( $trigger, $args );
                }
            );
        }
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
        // Per flush, not per process: one long Action Scheduler process would miss a workflow activated meanwhile.
        $this->active = [];
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
            // A workflow that needs YayMatik Pro while it isn't here doesn't start: a skipped rule would read as true.
            $this->active[ $trigger->id() ] ??= array_values( array_filter( $this->workflows->active_for_trigger( $trigger->id() ), fn( array $workflow ) => ! ProUsage::of( $workflow['graph'] ) ) );

            $workflows = $this->active[ $trigger->id() ];
            if ( ! $workflows ) {
                return;
            }
            if ( ! $trigger->claim( $args ) ) {
                $reason = $trigger->skip_reason( $args );
                if ( null !== $reason ) {
                    $this->skip_runs( $workflows, $trigger, $args, $reason, $parent_run );
                }
                return;
            }
            $context = $trigger->context( $args );
            $refs    = $context ? $trigger->resolved_refs( $args ) : null;
            if ( ! $context || null === $refs ) {
                return;
            }
            foreach ( $workflows as $workflow ) {
                $node = GraphSchema::trigger( $workflow['graph']['nodes'] ?? [] );
                if ( ! $this->listens( $workflow, $trigger ) || ! $trigger->matches( $context, $node['data']['config'] ) ) {
                    continue;
                }
                $claims = $trigger->claims_per_workflow();
                $key    = $claims ? $trigger->claim_key( $args ) : '';
                if ( $claims && ! $this->runs->claim_subject( (int) $workflow['id'], $key, $trigger->claim_cooldown( $node['data']['config'] ) ) ) {
                    continue;
                }
                $run_id = 0;
                try {
                    $run_id = $this->start_run( $workflow, $trigger, $args, $parent_run, $refs );
                } finally {
                    // A run that never started must not use up the claim: the next event retries.
                    if ( $claims && ! $run_id ) {
                        $this->runs->release_subject( (int) $workflow['id'], $key );
                    }
                }
                if ( $run_id ) {
                    $this->queue_run( $run_id );
                }
            }
        } catch ( \Throwable $e ) {
            Logger::error( "Could not start workflows for {$trigger->id()}: " . $e->getMessage(), [ 'args' => $args ] );
        }
    }

    /**
     * A finished skipped run, with the reason, for each workflow the refused event would have started.
     *
     * @param array[]    $workflows
     * @param Trigger    $trigger
     * @param array      $args
     * @param string     $reason
     * @param array|null $parent_run
     */
    private function skip_runs( array $workflows, Trigger $trigger, array $args, string $reason, ?array $parent_run ): void {
        $context = $trigger->context( $args );
        $refs    = $context ? $trigger->resolved_refs( $args ) : null;
        if ( ! $context || null === $refs ) {
            return;
        }
        $scope   = ( new Scope( [ 'trigger' => $refs ], null, [] ) )->to_array();
        $subject = $trigger->subject( $args );
        foreach ( $workflows as $workflow ) {
            $id = (int) $workflow['id'];
            if ( isset( $this->paused[ $id ] ) || ! $this->listens( $workflow, $trigger )
                || ! $trigger->matches( $context, GraphSchema::trigger( $workflow['graph']['nodes'] ?? [] )['data']['config'] ) ) {
                continue;
            }
            $version_id = $this->workflows->live_version_id( $id );
            if ( ! $version_id ) {
                continue;
            }
            $this->runs->create_run(
                [
                    'workflow_id'   => $id,
                    'version_id'    => $version_id,
                    'trigger_id'    => $trigger->id(),
                    'subject'       => $subject,
                    'scope'         => $scope,
                    'status'        => RunStore::STATUS_SKIPPED,
                    'error'         => $reason,
                    'parent_run_id' => $parent_run ? (int) $parent_run['id'] : 0,
                    'root_run_id'   => $parent_run ? (int) $parent_run['root_run_id'] : 0,
                    'depth'         => $parent_run ? (int) $parent_run['depth'] + 1 : 0,
                ]
            );
        }
    }

    /**
     * Whether the workflow's trigger node is this trigger with a valid config.
     *
     * @param array   $workflow
     * @param Trigger $trigger
     */
    private function listens( array $workflow, Trigger $trigger ): bool {
        $node = GraphSchema::trigger( $workflow['graph']['nodes'] ?? [] );
        return $node && $node['data']['block'] === $trigger->id() && true === $trigger->validate( $node['data']['config'] );
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
}
