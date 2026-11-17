<?php
namespace YayMatik\Tests\Unit\Pro\Workflow\Actions;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeMailer;
use YayMatik\Tests\Support\FakeOrder;
use YayMatik\Tests\Support\FakeWpdb;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Pro\Workflow\Actions\UpdateCustomerMeta;
use YayMatik\Pro\Workflow\Actions\UpdateOrderMeta;
use YayMatik\Workflow\Core\Steps\Step;
use YayMatik\Workflow\Core\Steps\StepResult;

require_once dirname( __DIR__, 4 ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__, 4 ) . '/Support/fake-wc.php';

/** Update order / customer custom field run(): ok / skipped / failed, and outputs exactly as declared. */
final class MetaRunTest extends TestCase {

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

    public function test_update_order_meta_skips_without_an_order(): void {
        $result = $this->run_step( new UpdateOrderMeta(), [ 'key' => 'k' ], new Scope() );

        $this->assertTrue( $result->is_skipped() );
        $this->assertNotSame( '', $result->message() );
    }
}
