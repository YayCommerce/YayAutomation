<?php
namespace YayMatik\Tests\Unit\Pro\Workflow\DataItems;

use PHPUnit\Framework\TestCase;
use YayMatik\Pro\Workflow\DataItems\OrderProData;
use YayMatik\Tests\Support\FakeOrder;

require_once dirname( __DIR__, 4 ) . '/Support/woocommerce-stubs.php';

/** The order fields YayMatik Pro adds. */
final class OrderProDataTest extends TestCase {

    /**
     * @param string $key
     * @param array  $data
     * @return mixed
     */
    private static function read( string $key, array $data ) {
        return ( ( new OrderProData() )->fields()[ $key ]['value'] )( new FakeOrder( $data ) );
    }

    public function test_plain_fields(): void {
        $this->assertSame( 'admin', self::read( 'created_via', [ 'created_via' => 'admin' ] ) );
        $this->assertSame( 'EUR', self::read( 'currency', [ 'currency' => 'EUR' ] ) );
        $this->assertSame( 5.5, self::read( 'discount_total', [ 'discount_total' => '5.5' ] ) );
        $this->assertSame( 4.0, self::read( 'shipping_total', [ 'shipping_total' => '4' ] ) );
        $this->assertSame( 2.0, self::read( 'refunded_total', [ 'total_refunded' => '2' ] ) );
    }

    public function test_pro_fields_sit_beside_the_free_ones(): void {
        $keys = array_keys( ( new OrderProData() )->fields() );

        $this->assertSame( [ 'shipping_country', 'created_via', 'currency', 'customer_note', 'discount_total' ], array_slice( $keys, array_search( 'shipping_country', $keys, true ), 5 ) );
    }

    public function test_custom_field_reads_text_and_refuses_blocked_keys(): void {
        $order       = new FakeOrder( [] );
        $order->meta = [
            '_vip'         => 'yes',
            '_list'        => [ 'a' ],
            '_order_total' => '9',
            '_yaymatik_x'  => '1',
        ];
        $meta        = ( new OrderProData() )->fields()['meta']['meta'];

        $this->assertSame( 'yes', $meta( $order, '_vip' ) );
        $this->assertSame( '', $meta( $order, '_list' ) );
        $this->assertSame( '', $meta( $order, '_order_total' ) );
        $this->assertSame( '', $meta( $order, '_yaymatik_x' ) );
    }

    public function test_custom_field_is_not_a_merge_tag(): void {
        $this->assertFalse( ( new OrderProData() )->fields()['meta']['merge_tag'] );
    }
}
