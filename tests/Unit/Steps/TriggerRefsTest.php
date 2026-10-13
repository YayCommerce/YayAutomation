<?php
namespace YayAutomation\Tests\Unit\Steps;

use PHPUnit\Framework\TestCase;
use YayAutomation\Tests\Support\FakeOrder;
use YayAutomation\Workflow\Data\Ref;
use YayAutomation\Workflow\Data\Scope;
use YayAutomation\Workflow\Steps\Triggers\OrderCreated;
use YayAutomation\Workflow\Steps\Triggers\OrderStatusChanged;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** What a run stores for a trigger event: refs and trigger-time facts, never the order itself. */
final class TriggerRefsTest extends TestCase {

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

    public function test_order_created_refs_the_order_and_its_billing_customer(): void {
        $refs = ( new OrderCreated() )->refs( [ 'order_id' => 12 ] );

        $this->assertSame( [ 'order', 'customer' ], array_keys( $refs ) );
        $this->assertSame( 'order:12', $refs['order']->key() );
        $this->assertSame( 'customer:order:12', $refs['customer']->key() );
    }

    public function test_status_changed_adds_from_and_to(): void {
        $refs = ( new OrderStatusChanged() )->refs(
            [
                'order_id' => 12,
                'from'     => 'pending',
                'to'       => 'processing',
            ]
        );

        $this->assertInstanceOf( Ref::class, $refs['order'] );
        $this->assertSame( 'pending', $refs['from'] );
        $this->assertSame( 'processing', $refs['to'] );
    }

    public function test_gone_or_non_order_subject_has_no_refs(): void {
        $this->assertNull( ( new OrderCreated() )->refs( [ 'order_id' => 99 ] ) );
        $this->assertNull( ( new OrderStatusChanged() )->refs( [ 'order_id' => 13 ] ) );
        $this->assertNull( ( new OrderCreated() )->refs( [] ) );
    }

    public function test_stored_form_holds_refs_only(): void {
        $refs   = ( new OrderStatusChanged() )->refs(
            [
                'order_id' => 12,
                'from'     => 'pending',
                'to'       => 'processing',
            ]
        );
        $stored = ( new Scope( [ 'trigger' => $refs ], null, [] ) )->to_array();

        $this->assertSame(
            [
                'order'    => [
                    '__ref' => [
                        'type' => 'order',
                        'id'   => '12',
                    ],
                ],
                'customer' => [
                    '__ref' => [
                        'type' => 'customer',
                        'id'   => 'order:12',
                    ],
                ],
                'from'     => 'pending',
                'to'       => 'processing',
            ],
            $stored['trigger']
        );
    }
}
