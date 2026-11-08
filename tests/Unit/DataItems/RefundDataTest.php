<?php
namespace YayMatik\Tests\Unit\DataItems;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeOrder;
use YayMatik\Workflow\DataItems\RefundData;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** A refund's fields, and "full refund" as it stood when the refund was made. */
final class RefundDataTest extends TestCase {

    protected function tearDown(): void {
        FakeOrder::$found = [];
    }

    /**
     * An order with these refunds, by id => amount; the refunds, by id.
     *
     * @param float $total
     * @param array $amounts
     * @return \WC_Order_Refund[]
     */
    private static function refunds( float $total, array $amounts ): array {
        $refunds = [];
        foreach ( $amounts as $id => $amount ) {
            $refunds[ $id ] = new \WC_Order_Refund(
                [
                    'id'        => $id,
                    'parent_id' => 12,
                    'amount'    => (string) $amount,
                    'reason'    => 'Broken',
                    'currency'  => 'USD',
                ]
            );
        }
        FakeOrder::$found = [
            12 => new FakeOrder(
                [
                    'id'      => 12,
                    'type'    => 'shop_order',
                    'total'   => (string) $total,
                    // WooCommerce lists the newest first.
                    'refunds' => array_reverse( array_values( $refunds ) ),
                ]
            ),
        ];
        return $refunds;
    }

    /**
     * @param string           $field
     * @param \WC_Order_Refund $refund
     * @return mixed
     */
    private static function value( string $field, \WC_Order_Refund $refund ) {
        return ( new RefundData() )->fields()[ $field ]['value']( $refund );
    }

    public function test_amount_and_reason(): void {
        $refund = self::refunds( 50.0, [ 30 => 20.5 ] )[30];

        $this->assertSame( 20.5, self::value( 'amount', $refund ) );
        $this->assertSame( 'Broken', self::value( 'reason', $refund ) );
    }

    public function test_a_partial_refund_is_not_full(): void {
        $this->assertFalse( self::value( 'is_full', self::refunds( 50.0, [ 30 => 20.0 ] )[30] ) );
    }

    public function test_partial_then_the_rest_keeps_the_first_partial(): void {
        $refunds = self::refunds(
            50.0,
            [
                30 => 20.0,
                31 => 30.0,
            ]
        );

        $this->assertFalse( self::value( 'is_full', $refunds[30] ), 'the first, read after the second landed' );
        $this->assertTrue( self::value( 'is_full', $refunds[31] ) );
    }

    public function test_cents_that_add_up_and_a_zero_total(): void {
        $refunds = self::refunds(
            0.3,
            [
                30 => 0.1,
                31 => 0.2,
            ]
        );
        $this->assertTrue( self::value( 'is_full', $refunds[31] ), 'no float drift' );
        $this->assertTrue( self::value( 'is_full', self::refunds( 0.0, [ 30 => 0.0 ] )[30] ), 'nothing left to refund' );
    }

    public function test_a_refund_whose_order_is_gone_is_not_full(): void {
        $refund           = self::refunds( 50.0, [ 30 => 50.0 ] )[30];
        FakeOrder::$found = [];

        $this->assertFalse( self::value( 'is_full', $refund ) );
    }
}
