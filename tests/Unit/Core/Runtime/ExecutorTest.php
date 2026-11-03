<?php
namespace YayMatik\Tests\Unit\Core\Runtime;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeClock;
use YayMatik\Tests\Support\FakeCondition;
use YayMatik\Tests\Support\FakeGraphSource;
use YayMatik\Tests\Support\FakeQueue;
use YayMatik\Tests\Support\FakeRunRepository;
use YayMatik\Tests\Support\FakeStep;
use YayMatik\Tests\Support\FakeTrigger;
use YayMatik\Tests\Support\FakeWorkflows;
use YayMatik\Workflow\Core\Data\GoneEntity;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Runtime\Executor;
use YayMatik\Workflow\Core\Steps\Step;
use YayMatik\Workflow\Core\Steps\StepResult;

/** The Executor over fakes: cursor, branches, error policy, waits, slices and safe resume. */
final class ExecutorTest extends TestCase {

    private const VERSION = 3;

    /** @var FakeRunRepository */
    private $runs;

    /** @var FakeGraphSource */
    private $graphs;

    /** @var FakeQueue */
    private $queue;

    /** @var FakeClock */
    private $clock;

    /** @var FakeWorkflows Workflow 9, active. */
    private $workflows;

    /** @var Step[] Step type id => step. */
    private $steps = [];

    protected function setUp(): void {
        $this->runs                    = new FakeRunRepository();
        $this->graphs                  = new FakeGraphSource();
        $this->queue                   = new FakeQueue();
        $this->clock                   = new FakeClock();
        $this->workflows               = new FakeWorkflows();
        $this->workflows->workflows[9] = [
            'id'     => '9',
            'status' => 'active',
            'graph'  => [],
        ];
        $this->steps                   = [
            'act'       => new FakeStep( 'act' ),
            'condition' => new FakeCondition(),
        ];
    }

    /**
     * A graph from a trigger `t` down. Nodes: id => [ block, config ]. Edges: [ source, target, handle? ].
     *
     * @param array $nodes Steps under the trigger.
     * @param array $edges The trigger's included.
     */
    private function graph( array $nodes, array $edges ): void {
        $graph = [
            'nodes' => [
                [
                    'id'   => 't',
                    'kind' => 'trigger',
                    'data' => [
                        'block'  => 'order_created',
                        'config' => [],
                    ],
                ],
            ],
            'edges' => [],
        ];
        foreach ( $nodes as $id => list( $block, $config ) ) {
            $graph['nodes'][] = [
                'id'   => $id,
                'kind' => 'action',
                'data' => [
                    'block'  => $block,
                    'config' => $config,
                ],
            ];
        }
        foreach ( $edges as $edge ) {
            $graph['edges'][] = [
                'source'       => $edge[0],
                'target'       => $edge[1],
                'sourceHandle' => $edge[2] ?? null,
            ];
        }
        $this->graphs->graphs[ self::VERSION ] = $graph;
    }

    /**
     * A claimed run.
     *
     * @param array $scope
     */
    private function new_run( array $scope = [] ): int {
        $id = $this->runs->create_run(
            [
                'workflow_id' => 9,
                'version_id'  => self::VERSION,
                'scope'       => $scope,
            ]
        );
        $this->runs->claim_run( $id, [ 'queued' ] );
        return $id;
    }

    /**
     * Claims and runs a slice, as the Worker does.
     *
     * @param int $id
     */
    private function slice( int $id ): string {
        $this->runs->claim_run( $id, [ 'queued', 'waiting', 'running' ] );
        $executor = new Executor(
            $this->runs,
            $this->graphs,
            $this->queue,
            $this->workflows,
            $this->clock,
            fn( string $block ) => $this->steps[ $block ] ?? null,
            fn() => new RefResolver( [] )
        );
        return $executor->run( $this->runs->find_run( $id ) );
    }

    /**
     * Step id => status of the run's rows, in the order they ran.
     *
     * @param int $id
     */
    private function statuses( int $id ): array {
        return array_column( $this->runs->steps( $id ), 'status', 'step_id' );
    }

    private function run_of( int $id ): array {
        return $this->runs->find_run( $id );
    }

    public function test_walks_the_steps_in_order_and_saves_the_cursor_after_each(): void {
        $this->graph(
            [
                'a' => [ 'act', [] ],
                'b' => [ 'act', [] ],
            ],
            [ [ 't', 'a' ], [ 'a', 'b' ] ]
        );
        $id = $this->new_run();

        $this->assertSame( 'completed', $this->slice( $id ) );
        $this->assertSame(
            [
                'a' => 'completed',
                'b' => 'completed',
            ],
            $this->statuses( $id )
        );
        $run = $this->run_of( $id );
        $this->assertSame( [ 'queue' => [] ], $run['position'] );
        $this->assertSame( [ 'to' => 'a@x.com' ], $run['scope']['steps']['b'] );
        $this->assertNotNull( $run['finished_at'] );
        // One save per step, then the final one.
        $this->assertSame( 3, $this->runs->saves );
    }

    public function test_condition_follows_then_on_yes_and_otherwise_on_no(): void {
        foreach ( [ [ true, 'yes_step' ], [ false, 'no_step' ] ] as list( $yes, $expected ) ) {
            $this->setUp();
            $this->graph(
                [
                    'c'        => [ 'condition', [ 'yes' => $yes ] ],
                    'yes_step' => [ 'act', [] ],
                    'no_step'  => [ 'act', [] ],
                ],
                [ [ 't', 'c' ], [ 'c', 'yes_step', 'then' ], [ 'c', 'no_step', 'otherwise' ] ]
            );
            $id = $this->new_run();

            $this->assertSame( 'completed', $this->slice( $id ) );
            $this->assertSame( [ 'c', $expected ], array_keys( $this->statuses( $id ) ) );
            $this->assertSame( [ 'result' => $yes ? 'yes' : 'no' ], $this->runs->steps( $id )[0]['output'] );
        }
    }

    public function test_failed_step_with_stop_fails_the_run_and_later_steps_dont_run(): void {
        $this->steps['boom'] = new FakeStep( 'boom', fn() => StepResult::failed( 'Mail server down.' ) );
        $this->graph(
            [
                'a' => [ 'boom', [] ],
                'b' => [ 'act', [] ],
            ],
            [ [ 't', 'a' ], [ 'a', 'b' ] ]
        );
        $id = $this->new_run();

        $this->assertSame( 'failed', $this->slice( $id ) );
        $this->assertSame( [ 'a' => 'failed' ], $this->statuses( $id ) );
        $this->assertSame( 'Mail server down.', $this->run_of( $id )['error'] );
        $this->assertSame( 'Mail server down.', $this->runs->steps( $id )[0]['error'] );
        $this->assertSame( 0, $this->steps['act']->runs );
    }

    public function test_failed_step_with_continue_goes_on(): void {
        $this->steps['boom'] = new FakeStep( 'boom', fn() => StepResult::failed( 'Mail server down.' ), Step::ON_ERROR_CONTINUE );
        $this->graph(
            [
                'a' => [ 'boom', [] ],
                'b' => [ 'act', [] ],
            ],
            [ [ 't', 'a' ], [ 'a', 'b' ] ]
        );
        $id = $this->new_run();

        $this->assertSame( 'completed', $this->slice( $id ) );
        $this->assertSame(
            [
                'a' => 'failed',
                'b' => 'completed',
            ],
            $this->statuses( $id )
        );
        $this->assertNull( $this->run_of( $id )['error'] );
    }

    public function test_a_throwing_step_fails_itself(): void {
        $this->steps['boom'] = new FakeStep(
            'boom',
            function () {
                throw new \RuntimeException( 'Oops.' );
            }
        );
        $this->graph( [ 'a' => [ 'boom', [] ] ], [ [ 't', 'a' ] ] );
        $id = $this->new_run();

        $this->assertSame( 'failed', $this->slice( $id ) );
        $this->assertSame( 'Oops.', $this->run_of( $id )['error'] );
    }

    public function test_a_gone_account_says_the_customer_was_deleted_and_other_customers_keep_their_text(): void {
        $this->assertSame( 'The customer was deleted.', Executor::gone_message( new GoneEntity( new Ref( 'customer', 'user:5' ) ) ) );
        $this->assertSame( 'Customer no longer exists.', Executor::gone_message( new GoneEntity( new Ref( 'customer', 'order:5' ) ) ) );
        $this->assertSame( 'Customer no longer exists.', Executor::gone_message( new GoneEntity( new Ref( 'customer', 'cart:5' ) ) ) );
    }

    public function test_gone_entity_skips_the_step_and_the_run_goes_on(): void {
        $this->steps['note'] = new FakeStep(
            'note',
            function ( Scope $scope ) {
                $scope->entity( 'order' );
                return StepResult::ok();
            }
        );
        $this->graph(
            [
                'a' => [ 'note', [] ],
                'b' => [ 'act', [] ],
            ],
            [ [ 't', 'a' ], [ 'a', 'b' ] ]
        );
        $id = $this->new_run( ( new Scope( [ 'trigger' => [ 'order' => new Ref( 'order', 99 ) ] ], null, [] ) )->to_array() );

        $this->assertSame( 'completed', $this->slice( $id ) );
        $rows = $this->runs->steps( $id );
        $this->assertSame( 'skipped', $rows[0]['status'] );
        $this->assertSame( 'Order no longer exists.', $rows[0]['error'] );
        $this->assertSame( 'completed', $rows[1]['status'] );
    }

    public function test_skipped_condition_ends_its_path(): void {
        $this->steps['condition'] = new class() extends \YayMatik\Workflow\Core\Steps\Condition {
            public function id(): string {
                return 'condition';
            }
            public function label(): string {
                return '';
            }
            public function description(): string {
                return '';
            }
            public function icon(): string {
                return '';
            }
            public function fields(): array {
                return [];
            }
            public function summary(): string {
                return '';
            }
            public function evaluate( Scope $scope, array $config ): bool {
                return true;
            }
            public function run( Scope $scope, array $config ): StepResult {
                return StepResult::skipped( 'Order no longer exists.' );
            }
        };
        $this->graph(
            [
                'c' => [ 'condition', [] ],
                'a' => [ 'act', [] ],
            ],
            [ [ 't', 'c' ], [ 'c', 'a', 'then' ] ]
        );
        $id = $this->new_run();

        $this->assertSame( 'completed', $this->slice( $id ) );
        $this->assertSame( [ 'c' => 'skipped' ], $this->statuses( $id ) );
    }

    public function test_invalid_or_unknown_step_fails(): void {
        $this->graph( [ 'a' => [ 'act', [ 'invalid' => true ] ] ], [ [ 't', 'a' ] ] );
        $id = $this->new_run();

        $this->assertSame( 'failed', $this->slice( $id ) );
        $this->assertSame( 'Invalid settings: Fake needs a recipient.', $this->run_of( $id )['error'] );
        $this->assertSame( 0, $this->steps['act']->runs );

        $this->setUp();
        $this->graph( [ 'a' => [ 'gone_pro_step', [] ] ], [ [ 't', 'a' ] ] );
        $id = $this->new_run();

        $this->assertSame( 'failed', $this->slice( $id ) );
        $this->assertSame( 'Step type "gone_pro_step" is not available.', $this->run_of( $id )['error'] );
        $this->assertSame( [ 'a' => 'failed' ], $this->statuses( $id ) );
    }

    public function test_input_is_the_unrendered_config_and_output_the_declared_outputs_only(): void {
        $this->steps['act'] = new FakeStep(
            'act',
            fn() => StepResult::ok(
                [
                    'to'   => 'a@x.com',
                    'body' => '<p>Hi Ann</p>',
                ]
            )
        );
        $this->graph( [ 'a' => [ 'act', [ 'message' => 'Hi {{ customer.first_name }}' ] ] ], [ [ 't', 'a' ] ] );
        $id = $this->new_run();

        $this->slice( $id );
        $row = $this->runs->steps( $id )[0];
        $this->assertSame( [ 'message' => 'Hi {{ customer.first_name }}' ], $row['input'] );
        $this->assertSame( [ 'to' => 'a@x.com' ], $row['output'] );
        $this->assertSame( [ 'to' => 'a@x.com' ], $this->run_of( $id )['scope']['steps']['a'] );
    }

    public function test_later_steps_read_earlier_outputs(): void {
        $seen                = null;
        $this->steps['read'] = new FakeStep(
            'read',
            function ( Scope $scope ) use ( &$seen ) {
                $seen = $scope->get( 'steps.a.to' );
                return StepResult::ok();
            }
        );
        $this->graph(
            [
                'a' => [ 'act', [] ],
                'b' => [ 'read', [] ],
            ],
            [ [ 't', 'a' ], [ 'a', 'b' ] ]
        );
        $this->slice( $this->new_run() );

        $this->assertSame( 'a@x.com', $seen );
    }

    public function test_a_step_knows_its_run_and_node(): void {
        $seen                = [];
        $this->steps['read'] = new FakeStep(
            'read',
            function ( Scope $scope ) use ( &$seen ) {
                $seen[] = [ $scope->get( 'run.id' ), $scope->get( 'run.step' ) ];
                return StepResult::ok();
            }
        );
        $this->graph(
            [
                'a' => [ 'read', [] ],
                'b' => [ 'read', [] ],
            ],
            [ [ 't', 'a' ], [ 'a', 'b' ] ]
        );
        $id = $this->new_run();
        $this->slice( $id );

        $this->assertSame( [ [ $id, 'a' ], [ $id, 'b' ] ], $seen );
    }

    public function test_wait_pauses_the_run_and_resumes_at_the_next_step(): void {
        $until               = $this->clock->now + 3600;
        $this->steps['wait'] = new FakeStep( 'wait', fn() => StepResult::wait( $until ) );
        $this->graph(
            [
                'w' => [ 'wait', [] ],
                'b' => [ 'act', [] ],
            ],
            [ [ 't', 'w' ], [ 'w', 'b' ] ]
        );
        $id = $this->new_run();

        $this->assertSame( 'waiting', $this->slice( $id ) );
        $run = $this->run_of( $id );
        $this->assertSame( '2026-10-12 11:00:00', $run['wait_until'] );
        $this->assertSame( [ 'queue' => [ 'w' ] ], $run['position'] );
        $this->assertSame( [ [ $id, $until ] ], $this->queue->jobs );
        $this->assertSame( [ 'w' => 'waiting' ], $this->statuses( $id ) );

        // Before its time no job can take it.
        $this->assertFalse( $this->runs->claim_run( $id, [ 'queued', 'waiting' ] ) );

        $this->runs->now  = '2026-10-12 11:00:00';
        $this->clock->now = $until;
        $this->assertSame( 'completed', $this->slice( $id ) );
        $this->assertNull( $this->run_of( $id )['wait_until'] );
        $this->assertSame(
            [
                'w' => 'completed',
                'b' => 'completed',
            ],
            $this->statuses( $id )
        );
        $this->assertSame( 1, $this->steps['wait']->runs );
    }

    public function test_turning_the_workflow_off_stops_the_run_before_its_next_step(): void {
        $this->steps['off'] = new FakeStep(
            'off',
            function () {
                $this->workflows->workflows[9]['status'] = 'inactive';
                return StepResult::ok( [] );
            }
        );
        $this->graph(
            [
                'a' => [ 'off', [] ],
                'b' => [ 'act', [] ],
            ],
            [ [ 't', 'a' ], [ 'a', 'b' ] ]
        );
        $id = $this->new_run();

        $this->assertSame( 'cancelled', $this->slice( $id ) );
        $run = $this->run_of( $id );
        $this->assertSame( 'The workflow was deactivated.', $run['error'] );
        $this->assertSame( [ 'queue' => [ 'b' ] ], $run['position'] );
        $this->assertNotNull( $run['finished_at'] );
        $this->assertSame( [ 'a' => 'completed' ], $this->statuses( $id ) );
        $this->assertSame( 0, $this->steps['act']->runs );
    }

    public function test_a_wait_that_ends_after_turning_off_cancels_the_run_and_closes_the_wait(): void {
        $until               = $this->clock->now + 3600;
        $this->steps['wait'] = new FakeStep( 'wait', fn() => StepResult::wait( $until ) );
        $this->graph(
            [
                'w' => [ 'wait', [] ],
                'b' => [ 'act', [] ],
            ],
            [ [ 't', 'w' ], [ 'w', 'b' ] ]
        );
        $id = $this->new_run();
        $this->assertSame( 'waiting', $this->slice( $id ) );

        $this->workflows->workflows[9]['status'] = 'inactive';
        $this->runs->now                         = '2026-10-12 11:00:00';
        $this->clock->now                        = $until;
        $this->assertSame( 'cancelled', $this->slice( $id ) );
        $this->assertNull( $this->run_of( $id )['wait_until'] );
        $row = $this->runs->steps( $id )[0];
        $this->assertSame( 'cancelled', $row['status'] );
        $this->assertSame( 'The workflow was deactivated.', $row['error'] );
        $this->assertNotNull( $row['finished_at'] );
        $this->assertSame( 0, $this->steps['act']->runs );
    }

    public function test_turned_off_while_the_wait_step_ran_cancels_instead_of_waiting(): void {
        $this->steps['wait'] = new FakeStep(
            'wait',
            function () {
                $this->workflows->workflows[9]['status'] = 'inactive';
                return StepResult::wait( $this->clock->now + 3600 );
            }
        );
        $this->graph( [ 'w' => [ 'wait', [] ] ], [ [ 't', 'w' ] ] );
        $id = $this->new_run();

        $this->assertSame( 'cancelled', $this->slice( $id ) );
        $this->assertNull( $this->run_of( $id )['wait_until'] );
        $this->assertSame( [ 'w' => 'cancelled' ], $this->statuses( $id ) );
        $this->assertSame( [], $this->queue->jobs );
    }

    /**
     * A claimed run of a trigger whose stop reason the test sets.
     *
     * @return array [ run id, trigger ].
     */
    private function stoppable_run(): array {
        $trigger                     = new FakeTrigger();
        $this->steps['fake_trigger'] = $trigger;
        $id                          = $this->runs->create_run(
            [
                'workflow_id' => 9,
                'version_id'  => self::VERSION,
                'trigger_id'  => 'fake_trigger',
                'subject'     => 'cart:5',
                'scope'       => [],
            ]
        );
        return [ $id, $trigger ];
    }

    public function test_a_trigger_stop_reason_cancels_before_the_next_step(): void {
        list( $id, $trigger ) = $this->stoppable_run();
        $this->steps['first'] = new FakeStep(
            'first',
            function () use ( $trigger ) {
                $trigger->reason = 'The shopper placed order #7.';
                return StepResult::ok();
            }
        );
        $this->graph(
            [
                'a' => [ 'first', [] ],
                'b' => [ 'act', [] ],
            ],
            [ [ 't', 'a' ], [ 'a', 'b' ] ]
        );

        $this->assertSame( 'cancelled', $this->slice( $id ) );
        $this->assertSame( 'The shopper placed order #7.', $this->run_of( $id )['error'] );
        $this->assertSame( 1, $this->steps['first']->runs );
        $this->assertSame( 0, $this->steps['act']->runs );
    }

    public function test_a_trigger_stop_reason_set_while_the_wait_step_ran_cancels_instead_of_waiting(): void {
        list( $id, $trigger ) = $this->stoppable_run();
        $this->steps['wait']  = new FakeStep(
            'wait',
            function () use ( $trigger ) {
                $trigger->reason = 'The cart was emptied or deleted.';
                return StepResult::wait( $this->clock->now + 3600 );
            }
        );
        $this->graph( [ 'w' => [ 'wait', [] ] ], [ [ 't', 'w' ] ] );

        $this->assertSame( 'cancelled', $this->slice( $id ) );
        $this->assertNull( $this->run_of( $id )['wait_until'] );
        $this->assertSame( 'The cart was emptied or deleted.', $this->run_of( $id )['error'] );
        $this->assertSame( [ 'w' => 'cancelled' ], $this->statuses( $id ) );
        $this->assertSame( [], $this->queue->jobs );
    }

    public function test_a_wait_ending_after_the_stop_reason_cancels_and_closes_the_wait(): void {
        list( $id, $trigger ) = $this->stoppable_run();
        $until                = $this->clock->now + 3600;
        $this->steps['wait']  = new FakeStep( 'wait', fn() => StepResult::wait( $until ) );
        $this->graph(
            [
                'w' => [ 'wait', [] ],
                'b' => [ 'act', [] ],
            ],
            [ [ 't', 'w' ], [ 'w', 'b' ] ]
        );
        $this->assertSame( 'waiting', $this->slice( $id ) );

        $trigger->reason  = 'The cart was emptied or deleted.';
        $this->clock->now = $until;
        $this->assertSame( 'cancelled', $this->slice( $id ) );
        $this->assertSame( 'The cart was emptied or deleted.', $this->runs->steps( $id )[0]['error'] );
        $this->assertSame( 0, $this->steps['act']->runs );
    }

    public function test_a_trigger_without_a_stop_reason_goes_on(): void {
        list( $id ) = $this->stoppable_run();
        $this->graph( [ 'a' => [ 'act', [] ] ], [ [ 't', 'a' ] ] );

        $this->assertSame( 'completed', $this->slice( $id ) );
        $this->assertSame( 1, $this->steps['act']->runs );
    }

    public function test_a_run_whose_trigger_is_not_a_trigger_step_goes_on(): void {
        $id = $this->runs->create_run(
            [
                'workflow_id' => 9,
                'version_id'  => self::VERSION,
                'trigger_id'  => 'act',
                'subject'     => 'cart:5',
                'scope'       => [],
            ]
        );
        $this->graph( [ 'a' => [ 'act', [] ] ], [ [ 't', 'a' ] ] );

        $this->assertSame( 'completed', $this->slice( $id ) );
    }

    public function test_wait_without_a_queue_fails_the_run(): void {
        $this->queue->available = false;
        $this->steps['wait']    = new FakeStep( 'wait', fn() => StepResult::wait( 1791802800 ) );
        $this->graph(
            [
                'w' => [ 'wait', [] ],
                'b' => [ 'act', [] ],
            ],
            [ [ 't', 'w' ], [ 'w', 'b' ] ]
        );
        $id = $this->new_run();

        $this->assertSame( 'failed', $this->slice( $id ) );
        $this->assertSame( [ 'w' => 'failed' ], $this->statuses( $id ) );
        $this->assertStringContainsString( 'Action Scheduler', $this->run_of( $id )['error'] );
    }

    public function test_slice_budget_queues_the_next_slice_which_finishes_the_run(): void {
        $this->clock->tick = 25.0;
        $this->graph(
            [
                'a' => [ 'act', [] ],
                'b' => [ 'act', [] ],
                'c' => [ 'act', [] ],
            ],
            [ [ 't', 'a' ], [ 'a', 'b' ], [ 'b', 'c' ] ]
        );
        $id = $this->new_run();

        // First check reads 0 s, so `a` and `b` run; the next check is over budget.
        $this->assertSame( 'queued', $this->slice( $id ) );
        $this->assertSame( [ 'queue' => [ 'c' ] ], $this->run_of( $id )['position'] );
        $this->assertSame( [ [ $id, null ] ], $this->queue->jobs );

        $this->clock->elapsed = 0.0;
        $this->clock->tick    = 0.0;
        $this->assertSame( 'completed', $this->slice( $id ) );
        $this->assertSame( 3, $this->steps['act']->runs );
        $this->assertCount( 3, $this->runs->steps( $id ) );
    }

    public function test_memory_budget_also_ends_the_slice(): void {
        $this->clock->memory = 0.85;
        $this->graph(
            [
                'a' => [ 'act', [] ],
                'b' => [ 'act', [] ],
            ],
            [ [ 't', 'a' ], [ 'a', 'b' ] ]
        );
        $id = $this->new_run();

        $this->assertSame( 'queued', $this->slice( $id ) );
        $this->assertSame( [ 'a' => 'completed' ], $this->statuses( $id ) );
    }

    public function test_no_slicing_without_a_queue(): void {
        $this->queue->available = false;
        $this->clock->tick      = 25.0;
        $this->graph(
            [
                'a' => [ 'act', [] ],
                'b' => [ 'act', [] ],
            ],
            [ [ 't', 'a' ], [ 'a', 'b' ] ]
        );

        $this->assertSame( 'completed', $this->slice( $this->new_run() ) );
    }

    public function test_failed_enqueue_leaves_the_run_queued(): void {
        $this->queue->succeeds = false;
        $this->clock->memory   = 0.9;
        $this->graph(
            [
                'a' => [ 'act', [] ],
                'b' => [ 'act', [] ],
            ],
            [ [ 't', 'a' ], [ 'a', 'b' ] ]
        );
        $id = $this->new_run();

        $this->assertSame( 'queued', $this->slice( $id ) );
        $this->assertSame( 'queued', $this->run_of( $id )['status'] );
        $this->assertSame( 1, $this->steps['act']->runs );
    }

    public function test_result_stored_before_the_cursor_is_not_run_again(): void {
        $this->graph(
            [
                'c' => [ 'condition', [ 'yes' => true ] ],
                'a' => [ 'act', [] ],
                'n' => [ 'act', [] ],
            ],
            [ [ 't', 'c' ], [ 'c', 'a', 'then' ], [ 'c', 'n', 'otherwise' ] ]
        );
        $id = $this->new_run();
        // Crash after the condition's row was finished (with "no"), before the cursor was saved.
        $row = $this->runs->start_step( $id, 'c', [ 'yes' => true ] );
        $this->runs->finish_step( $row, 'completed', [ 'result' => 'no' ] );

        $this->assertSame( 'completed', $this->slice( $id ) );
        $this->assertSame( 0, $this->steps['condition']->runs );
        // The stored branch is followed, not a fresh evaluation.
        $this->assertSame( [ 'c', 'n' ], array_keys( $this->statuses( $id ) ) );
        $this->assertSame( [ 'result' => 'no' ], $this->run_of( $id )['scope']['steps']['c'] );
    }

    public function test_step_left_running_fails_as_interrupted_and_its_policy_applies(): void {
        $this->steps['mail'] = new FakeStep( 'mail' );
        $this->graph(
            [
                'm' => [ 'mail', [] ],
                'b' => [ 'act', [] ],
            ],
            [ [ 't', 'm' ], [ 'm', 'b' ] ]
        );
        $id = $this->new_run();
        $this->runs->start_step( $id, 'm', [] );

        $this->assertSame( 'failed', $this->slice( $id ) );
        $this->assertSame( 0, $this->steps['mail']->runs );
        $this->assertSame( [ 'm' => 'failed' ], $this->statuses( $id ) );
        $this->assertSame( 'Interrupted.', $this->run_of( $id )['error'] );

        $this->setUp();
        $this->steps['mail'] = new FakeStep( 'mail', null, Step::ON_ERROR_CONTINUE );
        $this->graph(
            [
                'm' => [ 'mail', [] ],
                'b' => [ 'act', [] ],
            ],
            [ [ 't', 'm' ], [ 'm', 'b' ] ]
        );
        $id = $this->new_run();
        $this->runs->start_step( $id, 'm', [] );

        $this->assertSame( 'completed', $this->slice( $id ) );
        $this->assertSame( 0, $this->steps['mail']->runs );
        $this->assertSame(
            [
                'm' => 'failed',
                'b' => 'completed',
            ],
            $this->statuses( $id )
        );
    }

    public function test_runs_the_pinned_version(): void {
        $this->graph( [ 'a' => [ 'act', [] ] ], [ [ 't', 'a' ] ] );
        $id = $this->new_run();

        $this->graphs->graphs[ self::VERSION + 1 ] = [
            'nodes' => [],
            'edges' => [],
        ];

        $this->assertSame( 'completed', $this->slice( $id ) );
        $this->assertSame( [ 'a' => 'completed' ], $this->statuses( $id ) );
    }

    public function test_missing_version_or_storage_error_fails_the_run_without_throwing(): void {
        $id = $this->new_run();
        $this->assertSame( 'failed', $this->slice( $id ) );
        $this->assertStringContainsString( 'no longer exists', $this->run_of( $id )['error'] );

        $this->setUp();
        $this->graph( [ 'a' => [ 'act', [] ] ], [ [ 't', 'a' ] ] );
        $this->runs = new class() extends \YayMatik\Tests\Support\FakeRunRepository {
            public function start_step( int $run_id, string $step_id, array $input ): int {
                return 0;
            }
        };

        $id = $this->new_run();
        $this->assertSame( 'failed', $this->slice( $id ) );
        $this->assertSame( 'Could not save the step result.', $this->run_of( $id )['error'] );
        $this->assertSame( 0, $this->steps['act']->runs );
    }

    public function test_sanitize_that_throws_still_writes_a_failed_row_with_the_raw_config(): void {
        $this->graph( [ 'a' => [ 'act', [ 'sanitize_throws' => true ] ] ], [ [ 't', 'a' ] ] );
        $id = $this->new_run();

        $this->assertSame( 'failed', $this->slice( $id ) );
        $row = $this->runs->steps( $id )[0];
        $this->assertSame( 'a', $row['step_id'] );
        $this->assertSame( 'failed', $row['status'] );
        $this->assertSame( 'Bad stored config.', $row['error'] );
        $this->assertSame( [ 'sanitize_throws' => true ], $row['input'] );
        $this->assertSame( 'Bad stored config.', $this->run_of( $id )['error'] );
        $this->assertSame( 0, $this->steps['act']->runs );
    }

    public function test_wait_woken_early_waits_again_until_the_same_time(): void {
        $until               = $this->clock->now + 3600;
        $this->steps['wait'] = new FakeStep( 'wait', fn() => StepResult::wait( $until ) );
        $this->graph(
            [
                'w' => [ 'wait', [] ],
                'b' => [ 'act', [] ],
            ],
            [ [ 't', 'w' ], [ 'w', 'b' ] ]
        );
        $id = $this->new_run();
        $this->slice( $id );
        // A recovery job takes it early (it may take a `running` run): force that state.
        $this->runs->runs[ $id ]['status'] = 'running';

        $this->assertSame( 'waiting', $this->slice( $id ) );
        $this->assertSame( '2026-10-12 11:00:00', $this->run_of( $id )['wait_until'] );
        $this->assertSame( [ 'w' => 'waiting' ], $this->statuses( $id ) );
        $this->assertSame( 1, $this->steps['wait']->runs );
        $this->assertSame( 0, $this->steps['act']->runs );
    }

    public function test_waiting_row_without_a_saved_wait_fails_as_interrupted(): void {
        $this->steps['wait'] = new FakeStep( 'wait' );
        $this->graph(
            [
                'w' => [ 'wait', [] ],
                'b' => [ 'act', [] ],
            ],
            [ [ 't', 'w' ], [ 'w', 'b' ] ]
        );
        $id = $this->new_run();
        // Crash after the step's row was written `waiting`, before the run was saved as waiting.
        $row = $this->runs->start_step( $id, 'w', [] );
        $this->runs->finish_step( $row, 'waiting' );

        $this->assertSame( 'failed', $this->slice( $id ) );
        $this->assertSame( 'Interrupted.', $this->run_of( $id )['error'] );
        $this->assertSame( [ 'w' => 'failed' ], $this->statuses( $id ) );
        $this->assertSame( 0, $this->steps['act']->runs );
    }

    public function test_condition_that_only_implements_evaluate_follows_then_on_yes(): void {
        $this->steps['minimal'] = new class() extends \YayMatik\Workflow\Core\Steps\Condition {
            public function id(): string {
                return 'minimal';
            }
            public function label(): string {
                return '';
            }
            public function description(): string {
                return '';
            }
            public function icon(): string {
                return '';
            }
            public function fields(): array {
                return [];
            }
            public function summary(): string {
                return '';
            }
            public function evaluate( Scope $scope, array $config ): bool {
                return true;
            }
        };
        $this->graph(
            [
                'c'   => [ 'minimal', [] ],
                'yes' => [ 'act', [] ],
                'no'  => [ 'act', [] ],
            ],
            [ [ 't', 'c' ], [ 'c', 'yes', 'then' ], [ 'c', 'no', 'otherwise' ] ]
        );
        $id = $this->new_run();

        $this->assertSame( 'completed', $this->slice( $id ) );
        $this->assertSame( [ 'c', 'yes' ], array_keys( $this->statuses( $id ) ) );
        $this->assertSame( [ 'result' => 'yes' ], $this->runs->steps( $id )[0]['output'] );
    }
}
