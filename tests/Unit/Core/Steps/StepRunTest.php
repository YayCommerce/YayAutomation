<?php
namespace YayMatik\Tests\Unit\Core\Steps;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeMailer;
use YayMatik\Tests\Support\FakeOrder;
use YayMatik\Tests\Support\FakeWpdb;
use YayMatik\Workflow\Core\Data\GoneEntity;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Actions\AddOrderNote;
use YayMatik\Workflow\Actions\ChangeOrderStatus;
use YayMatik\Workflow\Actions\SendInternalEmail;
use YayMatik\Workflow\Actions\UpdateCustomerMeta;
use YayMatik\Workflow\Actions\UpdateOrderMeta;
use YayMatik\Workflow\Actions\Wait;
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

    /** @var mixed */
    private $wpdb;

    protected function setUp(): void {
        $this->wpdb          = $GLOBALS['wpdb'] ?? null;
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

    protected function tearDown(): void {
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- puts back what setUp() replaced.
        $GLOBALS['wpdb'] = $this->wpdb;
        unset( $GLOBALS['yaymatik_test_users'], $GLOBALS['yaymatik_test_meta_write_fails'] );
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
     * @param Step       $step
     * @param array      $raw
     * @param Scope|null $scope null = the order's.
     */
    private function run_step( Step $step, array $raw, ?Scope $scope = null ): StepResult {
        $config = $step->sanitize( $raw );
        $this->assertTrue( $step->validate( $config ), 'config is valid' );
        return $step->run( $scope ?? $this->scope(), $config );
    }

    /**
     * Ok, with output keys exactly as `outputs()` declares.
     *
     * @param Step       $step
     * @param StepResult $result
     */
    private function assert_ok_outputs( Step $step, StepResult $result ): void {
        $this->assertTrue( $result->is_ok(), $result->message() );
        $this->assertSame( array_keys( $step->outputs() ), array_keys( $result->outputs() ) );
    }

    public function test_wait_resumes_after_the_delay_in_fixed_seconds(): void {
        foreach ( [
            'minutes' => 60,
            'hours'   => 3600,
            'days'    => 86400,
            'weeks'   => 604800,
        ] as $unit => $seconds ) {
            $before = time();
            $result = $this->run_step(
                new Wait(),
                [
                    'delay' => [
                        'value' => 2,
                        'unit'  => $unit,
                    ],
                ]
            );
            $this->assertTrue( $result->is_wait(), $unit );
            $this->assertGreaterThanOrEqual( $before + 2 * $seconds, $result->until(), $unit );
            $this->assertLessThanOrEqual( time() + 2 * $seconds, $result->until(), $unit );
            $this->assertSame( [], $result->outputs(), $unit );
        }
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

    public function test_update_order_meta_outputs_no_values_and_adds_a_note(): void {
        $step                      = new UpdateOrderMeta();
        $this->order->meta['_vip'] = 'no';
        $result                    = $this->run_step(
            $step,
            [
                'key'   => '_vip',
                'value' => 'yes {{ order.number }}',
            ]
        );

        $this->assert_ok_outputs( $step, $result );
        $this->assertSame(
            [
                'key'     => '_vip',
                'changed' => true,
            ],
            $result->outputs()
        );
        $this->assertSame( [ '_vip' => 'yes 1012' ], $this->order->meta );
        $this->assertSame( 1, $this->order->saves );
        $this->assertSame( [ [ 'Custom field _vip changed from “no” to “yes 1012” by a YayMatik workflow.', 0 ] ], $this->order->notes );
    }

    public function test_update_order_meta_with_the_same_value_reads_unchanged_and_adds_no_note(): void {
        $this->order->meta['_vip'] = 'x';
        $result                    = $this->run_step(
            new UpdateOrderMeta(),
            [
                'key'   => '_vip',
                'value' => 'x',
            ]
        );
        $this->assertFalse( $result->outputs()['changed'] );
        $this->assertSame( [], $this->order->notes );
        $this->assertSame( 0, $this->order->saves );
    }

    public function test_update_order_meta_counts_a_new_empty_value_as_changed(): void {
        $result = $this->run_step( new UpdateOrderMeta(), [ 'key' => '_vip' ] );
        $this->assertTrue( $result->outputs()['changed'] );
        $this->assertSame( [ '_vip' => '' ], $this->order->meta );
    }

    public function test_update_order_meta_reads_a_non_scalar_old_value_as_empty(): void {
        $this->order->meta['_vip'] = [ 'a' ];
        $result                    = $this->run_step(
            new UpdateOrderMeta(),
            [
                'key'   => '_vip',
                'value' => '',
            ]
        );
        $this->assertTrue( $result->outputs()['changed'] );
        $this->assertSame( [ [ 'Custom field _vip changed from “” to “” by a YayMatik workflow.', 0 ] ], $this->order->notes );
    }

    /**
     * @dataProvider blocked_order_keys
     * @param string $key
     */
    public function test_update_order_meta_refuses_a_blocked_key_at_run_time( string $key ): void {
        $result = ( new UpdateOrderMeta() )->run(
            $this->scope(),
            [
                'key'   => $key,
                'value' => '0',
            ]
        );
        $this->assertSame( StepResult::FAILED, $result->status() );
        $this->assertSame( [], $this->order->meta );
        $this->assertSame( 0, $this->order->saves );
    }

    public function blocked_order_keys(): array {
        return [
            'internal'        => [ '_order_total' ],
            'internal, upper' => [ '_ORDER_TOTAL' ],
            'own prefix'      => [ '_yaymatik_x' ],
            'own, upper'      => [ '_YAYMATIK_X' ],
            'hidden'          => [ 'wp_vip' ],
            'hidden, upper'   => [ 'WP_VIP' ],
        ];
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

    /**
     * A run whose customer is this order's account (0 = a guest), with user 7's meta.
     *
     * @param int   $user_id
     * @param array $meta
     */
    private function customer_scope( int $user_id, array $meta = [] ): Scope {
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here; MetaKeys reads its prefix.
        $GLOBALS['wpdb']                = new FakeWpdb();
        $GLOBALS['yaymatik_test_users'] = [ 7 => (object) [ 'meta' => $meta ] ];
        $order                          = new FakeOrder(
            [
                'id'           => 12,
                'order_number' => '1012',
                'customer_id'  => $user_id,
            ]
        );
        return new Scope(
            [
                'trigger' => [
                    'order'    => new Ref( 'order', 12 ),
                    'customer' => new Ref( 'customer', 'order:12' ),
                ],
            ],
            new RefResolver(
                [
                    'customer' => fn() => $order,
                    'order'    => fn() => $order,
                ]
            )
        );
    }

    public function test_update_customer_meta_writes_and_outputs_no_values(): void {
        $step   = new UpdateCustomerMeta();
        $result = $this->run_step(
            $step,
            [
                'key'   => 'vip',
                'value' => "yes {{ order.number }} it's",
            ],
            $this->customer_scope( 7, [ 'vip' => 'no' ] )
        );

        $this->assert_ok_outputs( $step, $result );
        $this->assertSame(
            [
                'key'     => 'vip',
                'changed' => true,
            ],
            $result->outputs()
        );
        $this->assertSame( [ 'vip' => "yes 1012 it's" ], $GLOBALS['yaymatik_test_users'][7]->meta );
    }

    public function test_update_customer_meta_with_the_same_value_reads_unchanged(): void {
        $result = $this->run_step(
            new UpdateCustomerMeta(),
            [
                'key'   => 'vip',
                'value' => 'yes',
            ],
            $this->customer_scope( 7, [ 'vip' => 'yes' ] )
        );
        $this->assertFalse( $result->outputs()['changed'] );
        $result = $this->run_step( new UpdateCustomerMeta(), [ 'key' => 'new_key' ], $this->customer_scope( 7 ) );
        $this->assertTrue( $result->outputs()['changed'], 'a new empty value is a change' );
    }

    public function test_update_customer_meta_skips_a_guest(): void {
        $result = $this->run_step( new UpdateCustomerMeta(), [ 'key' => 'vip' ], $this->customer_scope( 0 ) );
        $this->assertTrue( $result->is_skipped() );
        $this->assertSame( 'This customer has no account.', $result->message() );
        $this->assertTrue( $this->run_step( new UpdateCustomerMeta(), [ 'key' => 'vip' ], new Scope() )->is_skipped(), 'no customer at all' );
    }

    public function test_update_customer_meta_skips_a_deleted_account(): void {
        $result = $this->run_step( new UpdateCustomerMeta(), [ 'key' => 'vip' ], $this->customer_scope( 9 ) );
        $this->assertTrue( $result->is_skipped() );
    }

    public function test_update_customer_meta_fails_when_the_write_does(): void {
        $scope                                     = $this->customer_scope( 7 );
        $GLOBALS['yaymatik_test_meta_write_fails'] = true;
        $result                                    = $this->run_step( new UpdateCustomerMeta(), [ 'key' => 'vip' ], $scope );
        $this->assertTrue( $result->is_failed() );
        $this->assertSame( 'Couldn’t save the custom field.', $result->message() );
    }

    public function test_update_customer_meta_refuses_a_blocked_key_at_run_time(): void {
        // A stored config that never passed validate().
        $result = ( new UpdateCustomerMeta() )->run(
            $this->customer_scope( 7 ),
            [
                'key'   => 'wp_capabilities',
                'value' => 'administrator',
            ]
        );
        $this->assertTrue( $result->is_failed() );
        $this->assertSame( [], $GLOBALS['yaymatik_test_users'][7]->meta );
    }

    public function test_update_customer_meta_refuses_a_key_the_collation_matches_to_a_blocked_row(): void {
        $scope                   = $this->customer_scope( 7 );
        $GLOBALS['wpdb']->result = [ 'wp_capabilities' ];
        $result                  = ( new UpdateCustomerMeta() )->run(
            $scope,
            [
                'key'   => 'wp_capabilitiés',
                'value' => 'administrator',
            ]
        );
        $this->assertTrue( $result->is_failed() );
        $this->assertSame( 'This custom field is protected and can’t be changed.', $result->message() );
        $this->assertSame( [], $GLOBALS['yaymatik_test_users'][7]->meta );
    }
}
