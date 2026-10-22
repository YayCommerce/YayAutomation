<?php
namespace YayMatik\Tests\Unit\Core\Steps;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeMailer;
use YayMatik\Tests\Support\FakeOrder;
use YayMatik\Workflow\Core\Data\GoneEntity;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Actions\AddOrderNote;
use YayMatik\Workflow\Actions\ChangeOrderStatus;
use YayMatik\Workflow\Actions\SendInternalEmail;
use YayMatik\Workflow\Actions\UpdateOrderMeta;
use YayMatik\Workflow\Conditions\Criteria;
use YayMatik\Workflow\Core\Steps\Step;
use YayMatik\Workflow\Core\Steps\StepResult;
use YayMatik\Workflow\Triggers\OrderCreated;
use YayMatik\Workflow\Triggers\OrderStatusChanged;

require_once dirname( __DIR__, 3 ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__, 3 ) . '/Support/fake-wc.php';

/** Each step's run(): ok / skipped / failed, and outputs exactly as declared. */
final class StepRunTest extends TestCase {

    /** @var FakeOrder */
    private $order;

    protected function setUp(): void {
        $this->order         = new FakeOrder(
            [
                'id'                 => 12,
                'order_number'       => '1012',
                'status'             => 'processing',
                'total'              => 99.5,
                'subtotal'           => 90,
                'billing_email'      => 'ann@x.com',
                'billing_first_name' => 'Ann',
            ]
        );
        FakeMailer::$current = new FakeMailer();
    }

    /** The scope a run of order 12 has: trigger refs, as the Dispatcher stores them, and status facts. */
    private function scope(): Scope {
        return new Scope(
            [
                'trigger' => [
                    'order'    => new Ref( 'order', 12 ),
                    'customer' => new Ref( 'customer', 'order:12' ),
                    'from'     => 'pending',
                    'to'       => 'processing',
                ],
            ],
            new RefResolver(
                [
                    'order'    => fn() => $this->order,
                    'customer' => fn() => $this->order,
                ]
            )
        );
    }

    /**
     * Runs a step with a raw config, as the Executor would: sanitized and valid.
     *
     * @param Step       $step  Step.
     * @param array      $raw   Raw config.
     * @param Scope|null $scope Scope; null = the order's.
     */
    private function run_step( Step $step, array $raw, ?Scope $scope = null ): StepResult {
        $config = $step->sanitize( $raw );
        $this->assertTrue( $step->validate( $config ), 'config is valid' );
        return $step->run( $scope ?? $this->scope(), $config );
    }

    /**
     * Ok, with output keys exactly as `outputs()` declares.
     *
     * @param Step       $step   Step.
     * @param StepResult $result Result.
     */
    private function assert_ok_outputs( Step $step, StepResult $result ): void {
        $this->assertTrue( $result->is_ok(), $result->message() );
        $this->assertSame( array_keys( $step->outputs() ), array_keys( $result->outputs() ) );
    }

    public function test_triggers_have_nothing_to_do(): void {
        foreach ( [ new OrderCreated(), new OrderStatusChanged() ] as $trigger ) {
            $result = $trigger->run( $this->scope(), $trigger->sanitize( [] ) );
            $this->assertTrue( $result->is_ok() );
            $this->assertSame( [], $result->outputs() );
        }
    }

    public function test_condition_outputs_yes_or_no(): void {
        $step = new Criteria();
        $rule = fn( string $op, string $value, string $type = 'value' ) => [
            'rules' => [
                [
                    'field'     => 'order.total',
                    'operator'  => $op,
                    'value'     => $value,
                    'valueType' => $type,
                ],
            ],
        ];

        $yes = $this->run_step( $step, $rule( 'greater_than', '50' ) );
        $this->assert_ok_outputs( $step, $yes );
        $this->assertSame( 'yes', $yes->outputs()['result'] );
        $this->assertSame( 'no', $this->run_step( $step, $rule( 'less_than', '50' ) )->outputs()['result'] );
        $this->assertSame( 'yes', $this->run_step( $step, $rule( 'greater_than', 'order.subtotal', 'variable' ) )->outputs()['result'] );
    }

    public function test_add_order_note_renders_tags_and_outputs_its_type(): void {
        $step   = new AddOrderNote();
        $result = $this->run_step(
            $step,
            [
                'note' => 'Thanks {{ customer.first_name }} for <b>#{{ order.number }}</b>',
                'type' => 'customer',
            ]
        );

        $this->assert_ok_outputs( $step, $result );
        $this->assertSame( [ 'type' => 'customer' ], $result->outputs() );
        $this->assertSame( [ [ 'Thanks Ann for <b>#1012</b>', 1 ] ], $this->order->notes );
    }

    public function test_change_order_status_outputs_from_and_to(): void {
        $step   = new ChangeOrderStatus();
        $result = $this->run_step( $step, [ 'status' => 'wc-completed' ] );

        $this->assert_ok_outputs( $step, $result );
        $this->assertSame(
            [
                'from' => 'processing',
                'to'   => 'completed',
            ],
            $result->outputs()
        );
        $this->assertSame( 'completed', $this->order->status_changes[0][0] );
    }

    public function test_change_order_status_to_the_same_status_changes_nothing(): void {
        $result = $this->run_step( new ChangeOrderStatus(), [ 'status' => 'processing' ] );

        $this->assertTrue( $result->is_ok() );
        $this->assertSame(
            [
                'from' => 'processing',
                'to'   => 'processing',
            ],
            $result->outputs()
        );
        $this->assertSame( [], $this->order->status_changes );
    }

    public function test_update_order_meta_saves_and_outputs_the_key(): void {
        $step   = new UpdateOrderMeta();
        $result = $this->run_step(
            $step,
            [
                'key'   => '_vip',
                'value' => 'yes {{ order.number }}',
            ]
        );

        $this->assert_ok_outputs( $step, $result );
        $this->assertSame( [ 'key' => '_vip' ], $result->outputs() );
        $this->assertSame( [ '_vip' => 'yes 1012' ], $this->order->meta );
        $this->assertSame( 1, $this->order->saves );
    }

    public function test_send_internal_email_outputs_who_it_was_sent_to_only(): void {
        $step   = new SendInternalEmail();
        $result = $this->run_step(
            $step,
            [
                'recipients' => 'a@x.com, b@x.com',
                'subject'    => 'Order {{ order.number }}',
                'message'    => 'From <{{ customer.email }}>',
            ]
        );

        $this->assert_ok_outputs( $step, $result );
        $this->assertSame( [ 'to' => [ 'a@x.com', 'b@x.com' ] ], $result->outputs(), 'no subject or body' );
        $this->assertSame( [ [ 'a@x.com', 'b@x.com' ], 'Order 1012', '<h1>Order 1012</h1>' . "<p>From &lt;ann@x.com&gt;</p>\n" ], FakeMailer::$current->sent[0] );
    }

    public function test_send_internal_email_falls_back_to_the_admin_email(): void {
        $result = $this->run_step(
            new SendInternalEmail(),
            [
                'subject' => 's',
                'message' => 'm',
            ]
        );

        $this->assertSame( [ 'to' => [ 'admin@shop.test' ] ], $result->outputs() );
        $this->assertSame( [ 'admin@shop.test' ], FakeMailer::$current->sent[0][0] );
    }

    public function test_send_internal_email_fails_when_the_mail_is_not_sent(): void {
        FakeMailer::$current->succeeds = false;
        $result                        = $this->run_step(
            new SendInternalEmail(),
            [
                'subject' => 's',
                'message' => 'm',
            ]
        );

        $this->assertTrue( $result->is_failed() );
        $this->assertSame( 'Couldn’t send the email.', $result->message() );
        $this->assertSame( [], $result->outputs() );
    }

    public function test_order_actions_skip_without_an_order(): void {
        $empty = new Scope();
        foreach (
            [
                [ new AddOrderNote(), [ 'note' => 'n' ] ],
                [ new ChangeOrderStatus(), [ 'status' => 'completed' ] ],
                [ new UpdateOrderMeta(), [ 'key' => 'k' ] ],
            ] as list( $step, $raw )
        ) {
            $result = $this->run_step( $step, $raw, $empty );
            $this->assertTrue( $result->is_skipped(), $step->id() );
            $this->assertNotSame( '', $result->message() );
        }
    }

    public function test_a_deleted_order_reaches_the_runner_as_gone(): void {
        $scope = new Scope( [ 'trigger' => [ 'order' => new Ref( 'order', 404 ) ] ], new RefResolver( [ 'order' => fn() => null ] ) );

        $this->expectException( GoneEntity::class );
        ( new AddOrderNote() )->run( $scope, ( new AddOrderNote() )->sanitize( [ 'note' => 'n' ] ) );
    }
}
