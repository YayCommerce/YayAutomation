<?php
namespace YayMatik\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeUser;
use YayMatik\Workflow\Core\Data\GoneEntity;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Steps\StepResult;
use YayMatik\Workflow\DataItems\CustomerData;
use YayMatik\Workflow\DataItems\OrderData;
use YayMatik\Workflow\Extension\Diagnostics;
use YayMatik\Workflow\Extension\ExtensionAction;
use YayMatik\Workflow\Extension\Extensions;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__, 2 ) . '/Support/extension-callables.php';

/** An action's `run` with the `Run` object, its result, and the declared `validate` / `setup_issue`. */
final class ExtensionActionTest extends TestCase {

    /** @var object[] Order id => order. */
    private $orders = [];

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_options'] = [];
        $GLOBALS['yaymatik_test_users']   = [
            5 => new FakeUser( 5, [ 'customer' ] ),
            7 => new FakeUser( 7, [ 'customer' ] ),
        ];
        $this->orders                     = [
            12 => new \WC_Order(
                [
                    'customer_id'   => 7,
                    'billing_email' => 'ann@example.test',
                ]
            ),
            13 => new \WC_Order(
                [
                    'customer_id'   => 0,
                    'billing_email' => 'guest@example.test',
                ]
            ),
        ];
    }

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_users'] );
    }

    /**
     * An action of module `acme` declared with these args.
     *
     * @param array $args
     */
    private static function action( array $args ): ExtensionAction {
        $actions = Extensions::collect(
            [],
            function ( $registrar ) use ( $args ) {
                $registrar->module(
                    [
                        'id'    => 'acme',
                        'label' => 'Acme',
                    ]
                )->action(
                    'acme_act',
                    $args + [
                        'label'       => 'Act',
                        'description' => 'Does it.',
                    ]
                );
            }
        )[0]->actions();
        return $actions[0];
    }

    /**
     * @param array $trigger The trigger scope section.
     */
    private function scope( array $trigger ): Scope {
        return new Scope(
            [ 'trigger' => $trigger ],
            new RefResolver(
                [
                    OrderData::ID    => fn( string $id ) => $this->orders[ (int) $id ] ?? null,
                    CustomerData::ID => function ( string $id ) {
                        list( $kind, $number ) = explode( ':', $id );
                        if ( 'order' === $kind ) {
                            return $this->orders[ (int) $number ] ?? null;
                        }
                        return isset( $GLOBALS['yaymatik_test_users'][ (int) $number ] ) ? new \WC_Customer( [ 'id' => (int) $number ] ) : null;
                    },
                ]
            ),
            [
                OrderData::ID    => new OrderData(),
                CustomerData::ID => new CustomerData(),
            ]
        );
    }

    /**
     * Runs the action with a run callback; returns the result.
     *
     * @param callable $run
     * @param array    $trigger
     */
    private function run_with( callable $run, array $trigger = [] ): StepResult {
        return self::action(
            [
                'run'     => $run,
                'outputs' => [
                    'balance' => [
                        'label' => 'Balance',
                        'type'  => 'number',
                    ],
                ],
            ]
        )->run( $this->scope( $trigger ), [] );
    }

    public function test_done_skip_and_fail(): void {
        $done = $this->run_with( fn( array $config, $run ) => $run->done( [ 'balance' => 35 ] ) );
        $this->assertTrue( $done->is_ok() );
        $this->assertSame( [ 'balance' => 35 ], $done->outputs() );

        $skip = $this->run_with( fn( array $config, $run ) => $run->skip( 'Guests can’t collect points.' ) );
        $this->assertSame( [ 'skipped', 'Guests can’t collect points.' ], [ $skip->status(), $skip->message() ] );

        $fail = $this->run_with( fn( array $config, $run ) => $run->fail( 'The points service is down.' ) );
        $this->assertSame( [ 'failed', 'The points service is down.' ], [ $fail->status(), $fail->message() ] );
    }

    public function test_any_other_return_fails_the_step_with_a_diagnostic(): void {
        foreach ( [ null, true, [ 'balance' => 1 ] ] as $returned ) {
            $result = $this->run_with( fn() => $returned );
            $this->assertSame( [ 'failed', 'The step returned no result.' ], [ $result->status(), $result->message() ] );
        }
        $this->assertSame( 'run returned something other than $run->done(), $run->skip() or $run->fail().', Diagnostics::recent( 24 )[0]['message'] );
    }

    public function test_a_throw_fails_the_step_with_its_message_and_a_diagnostic(): void {
        $result = $this->run_with(
            function () {
                throw new \RuntimeException( 'Points API timed out.' );
            }
        );

        $this->assertSame( [ 'failed', 'Points API timed out.' ], [ $result->status(), $result->message() ] );
        $this->assertSame( [ 'acme', 'run' ], [ Diagnostics::recent( 24 )[0]['module'], Diagnostics::recent( 24 )[0]['callback'] ] );
    }

    public function test_a_deleted_order_goes_on_to_the_executor(): void {
        $this->expectException( GoneEntity::class );
        $this->run_with( fn( array $config, $run ) => $run->done( [ 'balance' => $run->order()->get_total() ] ), [ 'order' => new Ref( 'order', 99 ) ] );
    }

    public function test_each_callable_kind_runs(): void {
        $callables = [
            'closure'  => fn( array $config, $run ) => $run->done( [ 'via' => 'closure' ] ),
            'method'   => [ new \YayMatik_Test_Extension_Callables(), 'method' ],
            'static'   => 'YayMatik_Test_Extension_Callables::run',
            'function' => 'yaymatik_test_extension_run',
        ];
        foreach ( $callables as $via => $callable ) {
            $action = self::action(
                [
                    'run'     => $callable,
                    'outputs' => [
                        'via' => [
                            'label' => 'Via',
                            'type'  => 'string',
                        ],
                    ],
                ]
            );
            $this->assertSame( [ 'via' => $via ], $action->run( $this->scope( [] ), [] )->outputs() );
        }
    }

    public function test_run_reads_order_user_and_email(): void {
        $seen    = [];
        $read    = function ( array $config, $run ) use ( &$seen ) {
            $user   = $run->user();
            $seen[] = [
                $run->order() ? 'order' : null,
                $user ? $user->ID : null,
                $run->customer_email(),
            ];
            return $run->done();
        };
        $account = [
            'order'    => new Ref( 'order', 12 ),
            'customer' => new Ref( 'customer', 'order:12' ),
        ];
        $guest   = [
            'order'    => new Ref( 'order', 13 ),
            'customer' => new Ref( 'customer', 'order:13' ),
            'user_id'  => 5,
        ];
        $this->run_with( $read, $account + [ 'user_id' => 5 ] );
        $this->run_with( $read, $guest );
        $this->run_with( $read, [ 'customer' => new Ref( 'customer', 'user:5' ) ] );
        $this->run_with( $read, [] );

        $this->assertSame(
            [
                [ 'order', 7, 'ann@example.test' ],
                [ 'order', 5, 'guest@example.test' ],
                [ null, 5, '' ],
                [ null, null, '' ],
            ],
            $seen
        );
    }

    public function test_get_gives_values_not_entities_and_render_fills_tags(): void {
        $seen = [];
        $this->run_with(
            function ( array $config, $run ) use ( &$seen ) {
                $seen = [ $run->get( 'order' ), $run->get( 'customer.email' ), $run->get( 'steps.coupon.code' ), $run->render( 'Hi {{ customer.email }}' ), $run->is_preview(), $run->cart(), $run->item( 'order' ) ];
                return $run->done();
            },
            [
                'order'    => new Ref( 'order', 12 ),
                'customer' => new Ref( 'customer', 'order:12' ),
            ]
        );

        $this->assertSame( [ null, 'ann@example.test', null, 'Hi ann@example.test', false, null, null ], $seen, 'no internal object gets out' );
    }

    public function test_validate_array_is_the_steps_context_error(): void {
        $action = self::action(
            [
                'run'      => fn( array $config, $run ) => $run->done(),
                'validate' => fn( array $config, array $available ) => in_array( 'order', $available, true ) ? [] : [ 'design' => 'This design needs an order.' ],
            ]
        );

        $this->assertNull( $action->context_error( [], [ 'order', 'customer' ] ) );
        $error = $action->context_error( [], [ 'customer' ] );
        $this->assertSame( 'This design needs an order.', $error->get_error_message() );
        $this->assertSame( 'design', $error->get_error_data()['field'] );
    }

    public function test_a_throwing_validate_is_an_issue_and_a_diagnostic(): void {
        $action = self::action(
            [
                'run'      => fn( array $config, $run ) => $run->done(),
                'validate' => function () {
                    throw new \RuntimeException( 'Boom.' );
                },
            ]
        );

        $this->assertSame( 'This step’s settings couldn’t be checked.', $action->context_error( [], [] )->get_error_message() );
        $this->assertSame( 'Boom.', Diagnostics::recent( 24 )[0]['message'] );
    }

    public function test_setup_issue_empty_string_is_fine(): void {
        $issue  = '';
        $action = self::action(
            [
                'run'         => fn( array $config, $run ) => $run->done(),
                'setup_issue' => function () use ( &$issue ) {
                    return $issue;
                },
            ]
        );

        $this->assertNull( $action->setup_issue() );
        $issue = 'Create a design in Acme first.';
        $this->assertSame( 'Create a design in Acme first.', $action->setup_issue() );
    }

    public function test_declared_keys_reach_the_core_action(): void {
        $action = self::action(
            [
                'run'      => fn( array $config, $run ) => $run->done(),
                'category' => 'customer',
                'requires' => [ 'customer' ],
                'emits'    => [ 'acme_points' ],
                'on_error' => 'continue',
                'summary'  => 'Add %points% points',
                'outputs'  => [
                    'balance' => [
                        'label' => 'Balance',
                        'type'  => 'number',
                    ],
                ],
            ]
        );

        $this->assertSame( [ 'customer', [ 'customer' ], [ 'acme_points' ], 'continue', 'Add %points% points', '' ], [ $action->category(), $action->requires(), $action->emits(), $action->on_error(), $action->summary(), $action->icon() ] );
        $this->assertSame( 'number', $action->outputs()['balance']['type']->to_string() );
    }
}
