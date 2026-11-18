<?php
namespace YayMatik\Tests\Unit\Pro\Workflow\Triggers;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeOrder;
use YayMatik\Pro\Workflow\Triggers\CustomerInactive;

require_once dirname( __DIR__, 4 ) . '/Support/woocommerce-stubs.php';

/** Customer inactive: refs, claims, days. */
final class CustomerInactiveTest extends TestCase {

    protected function setUp(): void {
        FakeOrder::$found = [
            12 => new FakeOrder(
                [
                    'id'   => 12,
                    'type' => 'shop_order',
                ]
            ),
            13 => new FakeOrder(
                [
                    'id'   => 13,
                    'type' => 'shop_order_refund',
                ]
            ),
        ];
    }

    protected function tearDown(): void {
        FakeOrder::$found = [];
    }

    public function test_customer_inactive_is_about_the_order_and_claims_per_workflow(): void {
        $trigger = new CustomerInactive();

        $this->assertSame( 'order:12', $trigger->subject( [ 'order_id' => 12 ] ) );
        $this->assertSame( 'customer', $trigger->category() );
        $this->assertTrue( $trigger->claims_per_workflow() );
        $this->assertSame( [ 'order', 'customer' ], array_keys( $trigger->refs( [ 'order_id' => 12 ] ) ), 'days is not offered as a variable; preview has none' );
    }

    public function test_customer_inactive_matches_only_its_days(): void {
        $trigger = new CustomerInactive();
        $context = $trigger->context(
            [
                'order_id' => 12,
                'days'     => 90,
            ]
        );

        $this->assertSame( 90, $context['days'] );
        $this->assertTrue( $trigger->matches( $context, [ 'days' => 90 ] ) );
        $this->assertFalse( $trigger->matches( $context, [ 'days' => 120 ] ) );
    }

    public function test_customer_inactive_saves_whole_days_in_range(): void {
        $trigger = new CustomerInactive();

        $this->assertSame( [ 'days' => 91 ], $trigger->sanitize( [ 'days' => 90.5 ] ) );
        $this->assertSame( [ 'days' => 90 ], $trigger->sanitize( [ 'days' => '90' ] ) );
        $this->assertSame( [ 'days' => 90 ], $trigger->sanitize( [] ), 'the default' );
        $this->assertTrue( $trigger->validate( [ 'days' => 7 ] ) );
        $this->assertTrue( $trigger->validate( [ 'days' => 730 ] ) );
        $this->assertNotTrue( $trigger->validate( [ 'days' => 6 ] ) );
        $this->assertNotTrue( $trigger->validate( [ 'days' => 731 ] ) );
    }
}
