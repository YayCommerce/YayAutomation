<?php
namespace YayMatik\Tests\Unit\Triggers;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeOrder;
use YayMatik\Workflow\Triggers\OrderPaid;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** Order paid: which status changes start it, and only once per order. */
final class OrderPaidTest extends TestCase {

    private const HOOKS = [ 'woocommerce_before_order_object_save', 'woocommerce_new_order', 'woocommerce_after_order_object_save', 'woocommerce_order_status_changed' ];

    /** @var array[] Args emitted. */
    private $emitted = [];

    protected function setUp(): void {
        ( new OrderPaid() )->listen(
            function ( array $args ) {
                $this->emitted[] = $args;
            }
        );
    }

    protected function tearDown(): void {
        foreach ( self::HOOKS as $hook ) {
            unset( $GLOBALS['yaymatik_test_filters'][ $hook ] );
        }
        FakeOrder::$found = [];
    }

    /**
     * @return array[] [ from, to, emits ]
     */
    public static function transitions(): array {
        return [
            'pending → processing'        => [ 'pending', 'processing', true ],
            'on-hold → completed'         => [ 'on-hold', 'completed', true ],
            'checkout-draft → processing' => [ 'checkout-draft', 'processing', true ],
            'failed → processing'         => [ 'failed', 'processing', true ],
            'processing → completed'      => [ 'processing', 'completed', false ],
            'completed → processing'      => [ 'completed', 'processing', false ],
            'pending → on-hold'           => [ 'pending', 'on-hold', false ],
            'processing → refunded'       => [ 'processing', 'refunded', false ],
        ];
    }

    /**
     * @dataProvider transitions
     *
     * @param string $from
     * @param string $to
     * @param bool   $emits
     */
    public function test_only_unpaid_to_paid_emits( string $from, string $to, bool $emits ): void {
        do_action( 'woocommerce_order_status_changed', 5, $from, $to );

        $this->assertSame( $emits ? [ [ 'order_id' => 5 ] ] : [], $this->emitted );
    }

    public function test_an_order_saved_already_paid_is_dropped_and_others_are_not(): void {
        $imported = new FakeOrder( [ 'status' => 'processing' ] );
        $pending  = new FakeOrder( [ 'status' => 'pending' ] );
        do_action( 'woocommerce_before_order_object_save', $imported );
        do_action( 'woocommerce_before_order_object_save', $pending );
        do_action( 'woocommerce_new_order', 5, $imported );
        do_action( 'woocommerce_new_order', 6, $pending );
        do_action( 'woocommerce_order_status_changed', 5, 'pending', 'processing' );
        do_action( 'woocommerce_order_status_changed', 6, 'pending', 'processing' );

        $this->assertSame( [ [ 'order_id' => 6 ] ], $this->emitted );
    }

    public function test_an_order_saved_already_paid_never_starts_it_later(): void {
        $imported = new FakeOrder(
            [
                'type'   => 'shop_order',
                'status' => 'processing',
            ]
        );
        do_action( 'woocommerce_before_order_object_save', $imported );
        $imported->data['id'] = 5;
        do_action( 'woocommerce_new_order', 5, $imported );
        do_action( 'woocommerce_after_order_object_save', $imported );
        FakeOrder::$found[5] = $imported;

        $this->assertNotSame( '', $imported->meta['_yaymatik_order_paid'] ?? '', 'marked as started' );
        $this->assertFalse( ( new OrderPaid() )->claim( [ 'order_id' => 5 ] ), 'a later unpaid → paid change in another request' );
    }

    public function test_a_draft_placed_paid_is_not_dropped(): void {
        // WooCommerce fires `woocommerce_new_order` again when a draft is placed, on an order that has an id.
        $draft = new FakeOrder(
            [
                'id'     => 7,
                'status' => 'processing',
            ]
        );
        do_action( 'woocommerce_before_order_object_save', $draft );
        do_action( 'woocommerce_new_order', 7, $draft );
        do_action( 'woocommerce_order_status_changed', 7, 'checkout-draft', 'processing' );

        $this->assertSame( [ [ 'order_id' => 7 ] ], $this->emitted );
    }

    public function test_claims_once_and_only_while_paid(): void {
        $order               = new FakeOrder(
            [
                'id'     => 5,
                'type'   => 'shop_order',
                'status' => 'processing',
            ]
        );
        FakeOrder::$found[5] = $order;
        $trigger             = new OrderPaid();

        $this->assertTrue( $trigger->claim( [ 'order_id' => 5 ] ) );
        $this->assertFalse( $trigger->claim( [ 'order_id' => 5 ] ), 'the second time' );

        $order->meta           = [];
        $order->data['status'] = 'cancelled';
        $this->assertFalse( $trigger->claim( [ 'order_id' => 5 ] ), 'no longer paid at shutdown' );
        $this->assertFalse( $trigger->claim( [ 'order_id' => 9 ] ), 'gone' );
    }
}
