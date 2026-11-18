<?php
namespace YayMatik\Tests\Unit\DataItems;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeOrder;
use YayMatik\Workflow\DataItems\OrderData;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** The order fields rules compare, through an order that records nothing but getters. */
final class OrderDataTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_categories'] );
    }

    /**
     * An item of this parent product.
     *
     * @param int    $product_id
     * @param string $name
     */
    private static function item( int $product_id, string $name ): \WC_Order_Item {
        return new class( $product_id, $name ) extends \WC_Order_Item {
            /** @var int */
            private $product_id;

            /** @var string */
            private $name;

            /**
             * @param int    $product_id
             * @param string $name
             */
            public function __construct( int $product_id, string $name ) {
                $this->product_id = $product_id;
                $this->name       = $name;
            }

            public function get_product_id() {
                return $this->product_id;
            }

            public function get_name() {
                return $this->name;
            }
        };
    }

    /**
     * @param string $key
     * @param array  $data
     * @return mixed
     */
    private static function read( string $key, array $data ) {
        return ( ( new OrderData() )->fields()[ $key ]['value'] )( new FakeOrder( $data ) );
    }

    public function test_a_product_listed_twice_counts_once(): void {
        $items = [ self::item( 4, 'Mug - Red' ), self::item( 4, 'Mug - Blue' ), self::item( 9, 'Tea' ) ];

        $this->assertSame( [ '4', '9' ], self::read( 'product_ids', [ 'line_items' => $items ] ) );
    }

    public function test_categories_of_all_products_once(): void {
        $GLOBALS['yaymatik_test_categories'] = [
            4 => [ 1, 2 ],
            9 => [ 2, 3 ],
        ];
        $items                               = [ self::item( 4, 'Mug' ), self::item( 9, 'Tea' ) ];

        $this->assertSame( [ '1', '2', '3' ], self::read( 'category_ids', [ 'line_items' => $items ] ) );
    }

    public function test_coupon_codes(): void {
        $this->assertSame( [ 'save10' ], self::read( 'coupon_codes', [ 'coupon_codes' => [ 3 => 'save10' ] ] ) );
    }

    public function test_plain_fields(): void {
        $this->assertSame( 'US', self::read( 'billing_country', [ 'billing_country' => 'US' ] ) );
        $this->assertSame( 'CA', self::read( 'shipping_country', [ 'shipping_country' => 'CA' ] ) );
        $this->assertSame( 'Hi', self::read( 'customer_note', [ 'customer_note' => 'Hi' ] ) );
    }

    public function test_the_free_edition_has_no_totals_origin_currency_custom_or_suggestion_fields(): void {
        $this->assertSame( [], array_intersect( [ 'discount_total', 'shipping_total', 'refunded_total', 'created_via', 'currency', 'has_cross_sells', 'has_upsells', 'cross_sell_products', 'upsell_products', 'meta' ], array_keys( ( new OrderData() )->fields() ) ) );
    }

    public function test_a_guest_has_no_account(): void {
        $this->assertTrue( self::read( 'is_guest', [ 'customer_id' => 0 ] ) );
        $this->assertFalse( self::read( 'is_guest', [ 'customer_id' => 5 ] ) );
    }

    public function test_dates_read_their_real_time(): void {
        $at     = new \DateTimeImmutable( '2026-01-02 03:04:05 UTC' );
        $date   = new class( $at->format( 'c' ) ) extends \DateTime {
            /**
             * @param string $format
             */
            public function date( $format ) {
                return $this->format( $format );
            }
        };
        $fields = ( new OrderData() )->fields();
        $paid   = new FakeOrder( [ 'date_paid' => $date ] );

        $this->assertSame( '2026-01-02', ( $fields['paid_date']['value'] )( $paid ) );
        $this->assertSame( $at->getTimestamp(), ( $fields['paid_date']['timestamp'] )( $paid ) );
        $this->assertSame( '', ( $fields['completed_date']['value'] )( new FakeOrder( [] ) ) );
        $this->assertNull( ( $fields['completed_date']['timestamp'] )( new FakeOrder( [] ) ) );
    }
}
