<?php
namespace YayMatik\Tests\Unit\Core\Runtime;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeClock;
use YayMatik\Tests\Support\FakeGraphSource;
use YayMatik\Tests\Support\FakeOrder;
use YayMatik\Tests\Support\FakeQueue;
use YayMatik\Tests\Support\FakeRunRepository;
use YayMatik\Tests\Support\FakeStep;
use YayMatik\Tests\Support\FakeWorkflows;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Core\Runtime\Dispatcher;
use YayMatik\Workflow\Core\Runtime\Worker;
use YayMatik\Workflow\Core\Steps\StepResult;
use YayMatik\Workflow\Triggers\OrderStatusChanged;

require_once dirname( __DIR__, 3 ) . '/Support/woocommerce-stubs.php';

/** Dispatcher (events → runs) and Worker (job → slice) over fakes. */
final class DispatcherWorkerTest extends TestCase {

    private const ORDER = 12;

    /** @var FakeRunRepository */
    private $runs;

    /** @var FakeQueue */
    private $queue;

    /** @var FakeWorkflows */
    private $workflows;

    /** @var FakeGraphSource */
    private $graphs;

    /** @var array Step type id => step. */
    private $steps;

    /** @var Worker */
    private $worker;

    /** @var Dispatcher */
    private $dispatcher;

    /** @var OrderStatusChanged */
    private $trigger;

    protected function setUp(): void {
        FakeOrder::$found = [
            self::ORDER => new FakeOrder(
                [
                    'id'   => self::ORDER,
                    'type' => 'shop_order',
                ]
            ),
        ];
        $this->runs       = new FakeRunRepository();
        $this->queue      = new FakeQueue();
        $this->workflows  = new FakeWorkflows();
        $this->graphs     = new FakeGraphSource();
        $this->trigger    = new OrderStatusChanged();
        $this->steps      = [ 'act' => new FakeStep( 'act' ) ];
        $this->build();
        $this->add_workflow( 1 );
    }

    protected function tearDown(): void {
        FakeOrder::$found = [];
    }

    private function build(): void {
        $this->worker     = new Worker(
            $this->runs,
            $this->graphs,
            $this->queue,
            $this->workflows,
            fn() => new FakeClock(),
            fn( string $id ) => $this->steps[ $id ] ?? null,
            fn() => new RefResolver(
                [
                    'order'    => fn( string $id ) => FakeOrder::$found[ (int) $id ] ?? null,
                    'customer' => fn( string $id ) => FakeOrder::$found[ (int) substr( $id, 6 ) ] ?? null,
                ]
            )
        );
        $this->dispatcher = new Dispatcher(
            $this->runs,
            $this->queue,
            $this->workflows,
            function ( int $run_id ) {
                $this->worker->run( $run_id );
            },
            [ $this->worker, 'current' ]
        );
    }

    /**
     * An active "Order status changed → act" workflow; its live version is `10 + id`.
     *
     * @param int    $id    Workflow id.
     * @param string $block Step under the trigger.
     */
    private function add_workflow( int $id, string $block = 'act' ): void {
        $graph                             = [
            'nodes' => [
                [
                    'id'   => 't',
                    'kind' => 'trigger',
                    'data' => [
                        'block'  => OrderStatusChanged::ID,
                        'config' => $this->trigger->sanitize( [] ),
                    ],
                ],
                [
                    'id'   => 'a',
                    'kind' => 'action',
                    'data' => [
                        'block'  => $block,
                        'config' => [],
                    ],
                ],
            ],
            'edges' => [
                [
                    'source'       => 't',
                    'target'       => 'a',
                    'sourceHandle' => null,
                ],
            ],
        ];
        $this->workflows->workflows[ $id ] = [
            'id'     => (string) $id,
            'status' => 'active',
            'graph'  => $graph,
        ];
        $this->workflows->versions[ $id ]  = 10 + $id;
        $this->graphs->graphs[ 10 + $id ]  = $graph;
    }

    private function event(): array {
        return [
            'order_id' => self::ORDER,
            'from'     => 'pending',
            'to'       => 'processing',
        ];
    }

    /**
     * A queued run of workflow 1, as the Dispatcher makes it.
     *
     * @param array $trigger Stored trigger section.
     */
    private function queued_run( array $trigger ): int {
        return $this->runs->create_run(
            [
                'workflow_id' => 1,
                'version_id'  => 11,
                'scope'       => ( new Scope( [ 'trigger' => $trigger ], null, [] ) )->to_array(),
            ]
        );
    }

    public function test_matching_event_creates_a_queued_run_with_refs_only_and_one_job(): void {
        $this->dispatcher->collect( $this->trigger, $this->event() );
        $this->dispatcher->collect( $this->trigger, $this->event() );
        $this->dispatcher->flush();

        $this->assertCount( 1, $this->runs->runs );
        $run = $this->runs->runs[1];
        $this->assertSame( 'queued', $run['status'] );
        $this->assertSame( 11, $run['version_id'] );
        $this->assertSame( 'order_status_changed', $run['trigger_id'] );
        $this->assertSame( 'order:12', $run['subject'] );
        $this->assertSame( [ 'trigger' ], array_keys( array_filter( $run['scope'] ) ) );
        $this->assertSame( [ '__ref' => [ 'type' => 'order', 'id' => '12' ] ], $run['scope']['trigger']['order'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
        $this->assertSame( 'processing', $run['scope']['trigger']['to'] );
        $this->assertSame( [ 0, 1, 0 ], [ $run['parent_run_id'], $run['root_run_id'], $run['depth'] ] );
        $this->assertSame( [ [ 1, null ] ], $this->queue->jobs );
    }

    public function test_no_match_no_workflow_or_gone_order_starts_nothing(): void {
        $this->workflows->workflows[1]['graph']['nodes'][0]['data']['config']['to'] = [ 'completed' ];
        $this->dispatcher->collect( $this->trigger, $this->event() );
        $gone             = $this->event();
        $gone['order_id'] = 99;
        $this->dispatcher->collect( $this->trigger, $gone );
        $this->dispatcher->flush();

        $this->assertSame( [], $this->runs->runs );
        $this->assertSame( [], $this->queue->jobs );
    }

    public function test_no_run_without_a_live_version(): void {
        $this->workflows->versions[1] = 0;
        $this->dispatcher->collect( $this->trigger, $this->event() );
        $this->dispatcher->flush();

        $this->assertSame( [], $this->runs->runs );
    }

    public function test_events_raised_by_a_run_carry_it_as_parent(): void {
        $this->add_workflow( 2 );
        $this->steps['raise']       = new FakeStep(
            'raise',
            function () {
                $this->dispatcher->collect( $this->trigger, $this->event() + [ 'note' => 'from run' ] );
                return StepResult::ok();
            }
        );
        $this->workflows->workflows = [];
        $this->add_workflow( 1, 'raise' );
        $this->add_workflow( 2 );
        $parent = $this->queued_run( [ 'order' => new Ref( 'order', self::ORDER ) ] );

        $this->assertSame( 'completed', $this->worker->run( $parent ) );
        $this->dispatcher->flush();

        // Workflow 1 is in the chain, so only workflow 2 starts, as a child of the run.
        $this->assertCount( 2, $this->runs->runs );
        $child = $this->runs->runs[2];
        $this->assertSame( 2, $child['workflow_id'] );
        $this->assertSame( [ $parent, $parent, 1 ], [ $child['parent_run_id'], $child['root_run_id'], $child['depth'] ] );
        $this->assertNull( $this->worker->current() );
    }

    public function test_workflow_in_the_parent_chain_is_not_started_again(): void {
        $this->add_workflow( 2 );
        $root  = $this->queued_run( [] );
        $child = $this->runs->create_run(
            [
                'workflow_id'   => 2,
                'version_id'    => 12,
                'parent_run_id' => $root,
                'root_run_id'   => $root,
                'depth'         => 1,
            ]
        );

        $this->dispatcher->start_run( $this->workflows->workflows[1], $this->trigger, $this->event(), $this->runs->find_run( $child ) );

        $this->assertCount( 2, $this->runs->runs );
    }

    public function test_chain_deeper_than_max_depth_is_not_started(): void {
        $parent = [
            'id'          => 500,
            'workflow_id' => 7,
            'root_run_id' => 400,
            'depth'       => Dispatcher::MAX_DEPTH,
        ];

        $this->assertSame( 0, $this->dispatcher->start_run( $this->workflows->workflows[1], $this->trigger, $this->event(), $parent ) );

        $parent['depth'] = Dispatcher::MAX_DEPTH - 1;
        $this->assertGreaterThan( 0, $this->dispatcher->start_run( $this->workflows->workflows[1], $this->trigger, $this->event(), $parent ) );
        $this->assertSame( Dispatcher::MAX_DEPTH, end( $this->runs->runs )['depth'] );
    }

    public function test_bulk_edit_of_150_orders_does_not_turn_the_workflow_off(): void {
        $this->runs->recent[1] = [ 'all' => 149 ];
        $this->dispatcher->collect( $this->trigger, $this->event() );
        $this->dispatcher->flush();

        $this->assertCount( 1, $this->runs->runs );
        $this->assertSame( [], $this->workflows->deactivated );
    }

    public function test_ceiling_across_all_depths_turns_the_workflow_off(): void {
        $this->runs->recent[1] = [ 'all' => Dispatcher::CEILING ];
        $this->dispatcher->collect( $this->trigger, $this->event() );
        $this->dispatcher->flush();

        $this->assertSame( [], $this->runs->runs );
        $this->assertSame( [ 1 ], $this->workflows->deactivated );
    }

    public function test_loop_limit_turns_the_workflow_off_once_and_starts_no_run(): void {
        $this->runs->recent[1] = [
            'all'     => Dispatcher::LOOP_LIMIT,
            'chained' => Dispatcher::LOOP_LIMIT,
        ];
        $this->dispatcher->collect( $this->trigger, $this->event() );
        $this->dispatcher->collect( $this->trigger, [ 'to' => 'on-hold' ] + $this->event() );
        $this->dispatcher->flush();

        $this->assertSame( [], $this->runs->runs );
        $this->assertSame( [ 1 ], $this->workflows->deactivated );
    }

    public function test_without_a_queue_the_run_goes_inline(): void {
        $this->queue->available = false;
        $this->dispatcher->collect( $this->trigger, $this->event() );
        $this->dispatcher->flush();

        $this->assertSame( 'completed', $this->runs->runs[1]['status'] );
        $this->assertSame( 1, $this->steps['act']->runs );
    }

    public function test_failed_enqueue_leaves_the_run_queued_and_never_runs_inline(): void {
        $this->queue->succeeds = false;
        $this->dispatcher->collect( $this->trigger, $this->event() );
        $this->dispatcher->flush();

        $this->assertSame( 'queued', $this->runs->runs[1]['status'] );
        $this->assertSame( 0, $this->steps['act']->runs );
    }

    public function test_dispatcher_never_throws(): void {
        $this->workflows = new class() extends FakeWorkflows {
            public function active_for_trigger( string $trigger_id ): array {
                throw new \RuntimeException( 'DB down.' );
            }
        };
        $this->build();
        $this->dispatcher->collect( $this->trigger, $this->event() );
        $this->dispatcher->flush();

        $this->assertSame( [], $this->runs->runs );
    }

    public function test_worker_runs_a_queued_run_to_the_end(): void {
        $id = $this->queued_run( [ 'order' => new Ref( 'order', self::ORDER ) ] );

        $this->assertSame( 'completed', $this->worker->run( $id ) );
        $this->assertSame( 1, $this->steps['act']->runs );
        // A second job for the same run finds nothing to take.
        $this->assertNull( $this->worker->run( $id ) );
    }

    public function test_worker_cancels_when_the_workflow_was_turned_off_or_deleted(): void {
        $id                                      = $this->queued_run( [] );
        $this->workflows->workflows[1]['status'] = 'inactive';

        $this->assertSame( 'cancelled', $this->worker->run( $id ) );
        $this->assertSame( 'The workflow was deactivated before this run started.', $this->runs->runs[ $id ]['error'] );

        $id = $this->queued_run( [] );
        unset( $this->workflows->workflows[1] );
        $this->assertSame( 'cancelled', $this->worker->run( $id ) );
        $this->assertSame( 0, $this->steps['act']->runs );
    }

    public function test_worker_skips_when_the_trigger_order_is_gone(): void {
        $id = $this->queued_run( [ 'order' => new Ref( 'order', 99 ) ] );

        $this->assertSame( 'skipped', $this->worker->run( $id ) );
        $this->assertSame( 'Order no longer exists.', $this->runs->runs[ $id ]['error'] );
        $this->assertNotNull( $this->runs->runs[ $id ]['finished_at'] );
        $this->assertSame( [], $this->runs->steps( $id ) );
    }

    public function test_start_checks_only_run_before_the_first_slice(): void {
        $this->graphs->graphs[11]['nodes'][] = [
            'id'   => 'b',
            'kind' => 'action',
            'data' => [
                'block'  => 'act',
                'config' => [],
            ],
        ];
        $this->graphs->graphs[11]['edges'][] = [
            'source'       => 'a',
            'target'       => 'b',
            'sourceHandle' => null,
        ];
        $id                                  = $this->queued_run( [] );
        $this->runs->save_run( $id, [ 'position' => [ 'queue' => [ 'b' ] ] ] );
        $this->workflows->workflows[1]['status'] = 'inactive';

        // Turning a workflow off doesn't stop a run already going.
        $this->assertSame( 'completed', $this->worker->run( $id ) );
        $this->assertSame( [ 'b' ], array_column( $this->runs->steps( $id ), 'step_id' ) );
    }

    public function test_recovery_takes_a_run_left_running_and_fails_the_interrupted_step(): void {
        $id = $this->queued_run( [] );
        $this->runs->claim_run( $id, [ 'queued' ] );
        $this->runs->save_run( $id, [ 'position' => [ 'queue' => [ 'a' ] ] ] );
        $this->runs->start_step( $id, 'a', [] );

        // A normal job can't take a running run; the recovery job can.
        $this->assertNull( $this->worker->run( $id ) );
        $this->assertSame( 'failed', $this->worker->run( $id, true ) );
        $this->assertSame( 'Interrupted.', $this->runs->runs[ $id ]['error'] );
        $this->assertSame( 0, $this->steps['act']->runs );
    }

    public function test_only_dead_run_jobs_get_a_recovery_job(): void {
        $this->worker->recover_job( ActionSchedulerQueue::HOOK, [ 5 ] );
        $this->worker->recover_job( ActionSchedulerQueue::RECOVER_HOOK, [ 6 ] );
        $this->worker->recover_job( 'woocommerce_cleanup', [ 7 ] );

        $this->assertSame( [ 5 ], $this->queue->recoveries );
    }

    public function test_worker_never_throws(): void {
        $this->runs = new class() extends FakeRunRepository {
            public function claim_run( int $id, array $from ): bool {
                throw new \RuntimeException( 'DB down.' );
            }
        };
        $this->build();

        $this->assertSame( 'failed', $this->worker->run( 1 ) );
        $this->assertNull( $this->worker->current() );
    }

    public function test_early_job_leaves_the_waiting_run_and_queues_one_at_wait_until(): void {
        $id = $this->queued_run( [] );
        $this->runs->save_run(
            $id,
            [
                'status'     => 'waiting',
                'position'   => [ 'queue' => [ 'a' ] ],
                'wait_until' => '2026-10-12 11:00:00',
            ]
        );

        $this->assertNull( $this->worker->run( $id ) );
        $this->assertSame( 'waiting', $this->runs->runs[ $id ]['status'] );
        $this->assertSame( [ [ $id, 1791802800 ] ], $this->queue->jobs );
        $this->assertSame( 0, $this->steps['act']->runs );
    }

    public function test_job_after_the_wait_takes_the_run_and_clears_wait_until(): void {
        $id = $this->queued_run( [] );
        $this->runs->save_run(
            $id,
            [
                'status'     => 'waiting',
                'position'   => [ 'queue' => [ 'a' ] ],
                'wait_until' => '2026-10-12 09:00:00',
            ]
        );

        $this->assertSame( 'completed', $this->worker->run( $id ) );
        $this->assertNull( $this->runs->runs[ $id ]['wait_until'] );
        $this->assertSame( [], $this->queue->jobs );
    }
}
