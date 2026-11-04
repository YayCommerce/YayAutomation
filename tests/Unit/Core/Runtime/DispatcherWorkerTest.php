<?php
namespace YayMatik\Tests\Unit\Core\Runtime;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeClock;
use YayMatik\Tests\Support\FakeGraphSource;
use YayMatik\Tests\Support\FakeOrder;
use YayMatik\Tests\Support\FakeQueue;
use YayMatik\Tests\Support\FakeRunRepository;
use YayMatik\Tests\Support\FakeStep;
use YayMatik\Tests\Support\FakeTrigger;
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
            [ $this->worker, 'current' ],
            fn() => []
        );
    }

    /**
     * An active "Order status changed → act" workflow; its live version is `10 + id`.
     *
     * @param int    $id
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
     * @param array $trigger
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

    /** The status trigger, opted in to a claim per workflow and subject. */
    private function claiming_trigger(): OrderStatusChanged {
        return new class() extends OrderStatusChanged {
            public function claims_per_workflow(): bool {
                return true;
            }
        };
    }

    public function test_claiming_trigger_starts_once_per_subject_across_requests(): void {
        $trigger = $this->claiming_trigger();
        $this->dispatcher->collect( $trigger, $this->event() );
        $this->dispatcher->flush();
        $this->dispatcher->collect( $trigger, $this->event() );
        $this->dispatcher->flush();

        $this->assertCount( 1, $this->runs->runs );
        $this->assertSame( [ '1|order:12' => true ], $this->runs->claims );
    }

    public function test_claim_is_per_workflow(): void {
        $this->add_workflow( 2 );
        $this->dispatcher->collect( $this->claiming_trigger(), $this->event() );
        $this->dispatcher->flush();

        $this->assertSame( [ 1, 2 ], array_column( $this->runs->runs, 'workflow_id' ) );
    }

    public function test_trigger_without_claims_starts_every_time_and_writes_none(): void {
        $this->dispatcher->collect( $this->trigger, $this->event() );
        $this->dispatcher->flush();
        $this->dispatcher->collect( $this->trigger, $this->event() );
        $this->dispatcher->flush();

        $this->assertCount( 2, $this->runs->runs );
        $this->assertSame( [], $this->runs->claims );
    }

    public function test_claim_is_released_when_the_run_could_not_start_and_retried_later(): void {
        $trigger                      = $this->claiming_trigger();
        $this->workflows->versions[1] = 0;
        $this->dispatcher->collect( $trigger, $this->event() );
        $this->dispatcher->flush();

        $this->assertSame( [], $this->runs->runs );
        $this->assertSame( [], $this->runs->claims );

        $this->workflows->versions[1] = 11;
        $this->dispatcher->collect( $trigger, $this->event() );
        $this->dispatcher->flush();

        $this->assertCount( 1, $this->runs->runs );
        $this->assertSame( [ '1|order:12' => true ], $this->runs->claims );
    }

    public function test_claim_is_released_when_the_rate_limit_stops_the_run(): void {
        $this->runs->recent = [ 1 => [ 'all' => Dispatcher::CEILING ] ];
        $this->dispatcher->collect( $this->claiming_trigger(), $this->event() );
        $this->dispatcher->flush();

        $this->assertSame( [], $this->runs->runs );
        $this->assertSame( [], $this->runs->claims );
    }

    public function test_claim_is_released_when_starting_the_run_throws(): void {
        $runs       = new class() extends FakeRunRepository {
            public function create_run( array $run ): int {
                throw new \RuntimeException( 'write failed' );
            }
        };
        $dispatcher = new Dispatcher( $runs, $this->queue, $this->workflows, function (): void {}, fn() => null, fn() => [] );
        $dispatcher->collect( $this->claiming_trigger(), $this->event() );
        $dispatcher->flush();

        $this->assertSame( [], $runs->claims );
        $this->assertSame( [], $this->queue->jobs );
    }

    public function test_turning_the_workflow_off_before_a_queued_run_starts_releases_its_claim(): void {
        $trigger                               = $this->claiming_trigger();
        $this->steps[ OrderStatusChanged::ID ] = $trigger;
        $this->dispatcher->collect( $trigger, $this->event() );
        $this->dispatcher->flush();
        $this->workflows->deactivate( 1 );

        $this->assertSame( 'cancelled', $this->worker->run( 1 ) );
        $this->assertSame( [], $this->runs->claims );
    }

    public function test_turning_the_workflow_off_keeps_the_claim_when_a_step_already_ran(): void {
        $trigger                               = $this->claiming_trigger();
        $this->steps[ OrderStatusChanged::ID ] = $trigger;
        $this->dispatcher->collect( $trigger, $this->event() );
        $this->dispatcher->flush();
        $this->runs->start_step( 1, 'a', [] );
        $this->workflows->deactivate( 1 );

        $this->assertSame( 'cancelled', $this->worker->run( 1 ) );
        $this->assertSame( [ '1|order:12' => true ], $this->runs->claims );
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
        $this->assertSame( 'The workflow was deactivated.', $this->runs->runs[ $id ]['error'] );

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

    public function test_worker_cancels_before_the_first_step_on_a_stop_reason_even_if_a_ref_is_gone(): void {
        $trigger                     = new FakeTrigger();
        $trigger->reason             = 'The cart was emptied or deleted.';
        $this->steps['fake_trigger'] = $trigger;
        $id                          = $this->runs->create_run(
            [
                'workflow_id' => 1,
                'version_id'  => 11,
                'trigger_id'  => 'fake_trigger',
                'subject'     => 'cart:5',
                'scope'       => ( new Scope( [ 'trigger' => [ 'order' => new Ref( 'order', 99 ) ] ], null, [] ) )->to_array(),
            ]
        );

        $this->assertSame( 'cancelled', $this->worker->run( $id ) );
        $this->assertSame( 'The cart was emptied or deleted.', $this->runs->runs[ $id ]['error'] );
        $this->assertSame( 0, $this->steps['act']->runs );
    }

    public function test_turning_off_stops_a_run_already_going_before_its_next_step(): void {
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

        $this->assertSame( 'cancelled', $this->worker->run( $id ) );
        $this->assertSame( 'The workflow was deactivated.', $this->runs->runs[ $id ]['error'] );
        $this->assertSame( [], $this->runs->steps( $id ) );
    }

    public function test_cancelling_before_the_first_step_closes_a_first_step_left_running(): void {
        $id = $this->queued_run( [] );
        $this->runs->claim_run( $id, [ 'queued' ] );
        $this->runs->start_step( $id, 'a', [] );
        $this->workflows->workflows[1]['status'] = 'inactive';

        $this->assertSame( 'cancelled', $this->worker->run( $id, true ) );
        $row = $this->runs->steps( $id )[0];
        $this->assertSame( 'cancelled', $row['status'] );
        $this->assertSame( 'The workflow was deactivated.', $row['error'] );
        $this->assertNotNull( $row['finished_at'] );
    }

    public function test_a_cancelled_runs_job_does_nothing(): void {
        $id = $this->queued_run( [] );
        $this->runs->save_run(
            $id,
            [
                'status'   => 'cancelled',
                'position' => [ 'queue' => [ 'a' ] ],
            ]
        );
        $saves = $this->runs->saves;

        $this->assertNull( $this->worker->run( $id ) );
        $this->assertNull( $this->worker->run( $id, true ) );
        $this->assertSame( 'cancelled', $this->runs->runs[ $id ]['status'] );
        $this->assertSame( $saves, $this->runs->saves );
        $this->assertSame( [], $this->queue->jobs );
        $this->assertSame( 0, $this->steps['act']->runs );
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

    /**
     * A Dispatcher over these triggers and the test's workflows.
     *
     * @param FakeTrigger[] $triggers
     */
    private function dispatcher_with( array $triggers ): Dispatcher {
        return new Dispatcher( $this->runs, $this->queue, $this->workflows, function (): void {}, fn() => null, fn() => $triggers );
    }

    public function test_listens_only_to_triggers_with_an_active_workflow(): void {
        $active = new FakeTrigger( OrderStatusChanged::ID );
        $idle   = new FakeTrigger( 'idle' );
        $this->dispatcher_with( [ $active, $idle ] )->listen_active();

        $this->assertSame( 1, $active->listens );
        $this->assertSame( 0, $idle->listens );
    }

    public function test_a_change_adds_a_new_trigger_once(): void {
        $active     = new FakeTrigger( OrderStatusChanged::ID );
        $later      = new FakeTrigger( 'later' );
        $dispatcher = $this->dispatcher_with( [ $active, $later ] );
        $dispatcher->listen_active();

        $this->workflows->workflows[2] = [
            'id'     => 2,
            'status' => 'active',
            'graph'  => [
                'nodes' => [
                    [
                        'kind' => 'trigger',
                        'data' => [ 'block' => 'later' ],
                    ],
                ],
            ],
        ];
        $dispatcher->listen_active();
        $dispatcher->listen_active();

        $this->assertSame( 1, $active->listens );
        $this->assertSame( 1, $later->listens );
    }

    public function test_an_unreadable_list_listens_to_every_trigger(): void {
        $this->workflows->list_fails = true;
        $idle                        = new FakeTrigger( 'idle' );
        $this->dispatcher_with( [ $idle ] )->listen_active();

        $this->assertSame( 1, $idle->listens );
    }

    public function test_active_workflows_are_read_once_per_flush(): void {
        $this->dispatcher->collect( $this->trigger, self::paid( 12 ) );
        $this->dispatcher->collect( $this->trigger, self::paid( 13 ) );
        $this->dispatcher->flush();
        $this->assertSame( 1, $this->workflows->lookups );

        $this->dispatcher->collect( $this->trigger, self::paid( 14 ) );
        $this->dispatcher->flush();
        $this->assertSame( 2, $this->workflows->lookups );
    }

    /**
     * A status event for an order.
     *
     * @param int $order_id
     */
    private static function paid( int $order_id ): array {
        return [
            'order_id' => $order_id,
            'from'     => 'pending',
            'to'       => 'processing',
        ];
    }
}
