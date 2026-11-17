<?php
namespace YayMatik\Tests\Unit\Triggers;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeOrder;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Steps\Trigger;
use YayMatik\Workflow\Triggers\CustomerAccountCreated;
use YayMatik\Workflow\Triggers\OrderCreated;
use YayMatik\Workflow\Triggers\OrderNoteAdded;
use YayMatik\Workflow\Triggers\OrderPaid;
use YayMatik\Workflow\Triggers\OrderRefunded;
use YayMatik\Workflow\Triggers\OrderStatusChanged;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** What a run stores for a trigger event: refs and trigger-time facts, never the order itself. */
final class TriggerRefsTest extends TestCase {

    /** @var callable|null */
    private $provides_filter = null;

    /** @var callable|null */
    private $refs_filter = null;

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
        if ( $this->provides_filter ) {
            remove_filter( 'yaymatik_trigger_provides', $this->provides_filter );
        }
        if ( $this->refs_filter ) {
            remove_filter( 'yaymatik_trigger_refs', $this->refs_filter );
        }
    }

    public function test_account_created_is_about_the_user_provides_only_the_customer_and_a_gone_user_has_no_refs(): void {
        $trigger = new CustomerAccountCreated();

        $this->assertSame( 'user:7', $trigger->subject( [ 'customer_id' => 7 ] ) );
        $this->assertSame( [ 'customer' ], $trigger->provides() );
        $this->assertSame( 'customer', $trigger->category() );
        $this->assertNull( $trigger->refs( [ 'customer_id' => 7 ] ) );
        $this->assertNull( $trigger->context( [ 'customer_id' => 7 ] ) );
    }

    public function test_order_created_refs_the_order_and_its_billing_customer(): void {
        $refs = ( new OrderCreated() )->refs( [ 'order_id' => 12 ] );

        $this->assertSame( [ 'order', 'customer' ], array_keys( $refs ) );
        $this->assertSame( 'order:12', $refs['order']->key() );
        $this->assertSame( 'customer:order:12', $refs['customer']->key() );
    }

    public function test_paid_and_note_triggers_are_about_the_order_and_store_refs_only(): void {
        foreach ( [ new OrderPaid(), new OrderNoteAdded() ] as $trigger ) {
            $args = [
                'order_id' => 12,
                'note_id'  => 40,
                'by_run'   => true,
            ];
            $this->assertSame( 'order:12', $trigger->subject( $args ) );
            $this->assertSame( 'order', $trigger->category() );
            $this->assertFalse( $trigger->claims_per_workflow() );
            $this->assertSame( [ 'order', 'customer' ], array_keys( $trigger->refs( $args ) ), 'the note is not stored in the run' );
            $this->assertNull( $trigger->refs( [ 'order_id' => 13 ] ) );
        }
    }

    public function test_refunded_adds_the_refund_and_needs_it(): void {
        FakeOrder::$found[30] = new \WC_Order_Refund(
            [
                'id'        => 30,
                'parent_id' => 12,
            ]
        );
        FakeOrder::$found[31] = new \WC_Order_Refund(
            [
                'id'        => 31,
                'parent_id' => 99,
            ]
        );
        $trigger              = new OrderRefunded();
        $args                 = [
            'order_id'  => 12,
            'refund_id' => 30,
        ];
        $refs                 = $trigger->refs( $args );

        $this->assertSame( [ 'order', 'customer', 'refund' ], $trigger->provides() );
        $this->assertSame( 'order:12', $trigger->subject( $args ) );
        $this->assertSame( 'refund:30', $refs['refund']->key() );
        $this->assertSame( [ 'order', 'customer', 'refund' ], array_keys( $trigger->context( $args ) ) );
        $this->assertNull( $trigger->refs( [ 'order_id' => 12 ] ), 'no refund_id' );
        $this->assertNull(
            $trigger->refs(
                [
                    'order_id'  => 12,
                    'refund_id' => 31,
                ]
            ),
            'another order\'s refund'
        );
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

    public function test_integrations_can_extend_trigger_data_without_replacing_the_trigger(): void {
        $this->provides_filter = function ( array $items, Trigger $trigger ): array {
            if ( OrderCreated::ID === $trigger->id() ) {
                $items[] = 'integration_data';
            }
            return $items;
        };
        $this->refs_filter     = function ( array $refs, Trigger $trigger, array $args ): array {
            if ( OrderCreated::ID === $trigger->id() ) {
                $refs['integration_data'] = [ 'order_id' => $args['order_id'] ];
            }
            return $refs;
        };
        add_filter( 'yaymatik_trigger_provides', $this->provides_filter );
        add_filter( 'yaymatik_trigger_refs', $this->refs_filter );

        $trigger = new OrderCreated();

        $this->assertSame( [ 'order', 'customer', 'integration_data' ], $trigger->resolved_provides() );
        $this->assertSame( [ 'order_id' => 12 ], $trigger->resolved_refs( [ 'order_id' => 12 ] )['integration_data'] );
        $this->assertNull( $trigger->resolved_refs( [ 'order_id' => 99 ] ), 'a filter cannot revive a missing subject' );
    }

    public function test_multiple_integrations_compose_on_the_same_trigger(): void {
        $first  = fn( array $items ): array => array_merge( $items, [ 'first_integration' ] );
        $second = fn( array $items ): array => array_merge( $items, [ 'second_integration' ] );
        add_filter( 'yaymatik_trigger_provides', $first );
        add_filter( 'yaymatik_trigger_provides', $second );

        try {
            $this->assertSame(
                [ 'order', 'customer', 'first_integration', 'second_integration' ],
                ( new OrderCreated() )->resolved_provides()
            );
        } finally {
            remove_filter( 'yaymatik_trigger_provides', $first );
            remove_filter( 'yaymatik_trigger_provides', $second );
        }
    }
}
