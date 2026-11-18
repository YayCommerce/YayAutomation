<?php
namespace YayMatik\Tests\Unit\Pro\Workflow\Core\Email;

use PHPUnit\Framework\TestCase;
use YayMatik\Pro\Workflow\Core\Email\OrderGrid;

require_once dirname( __DIR__, 5 ) . '/Support/woocommerce-stubs.php';

/** An order's suggested products: hidden ones skipped, read in chunks up to a cap. */
final class OrderGridTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_primed'] = [];
    }

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_products'], $GLOBALS['yaymatik_test_primed'] );
    }

    /**
     * An order of one product with these cross-sells; ids from `$visible_from` on are shown.
     *
     * @param int[] $suggested
     * @param int   $visible_from
     */
    private static function order_suggesting( array $suggested, int $visible_from ): \WC_Order {
        foreach ( $suggested as $id ) {
            $GLOBALS['yaymatik_test_products'][ $id ] = new \WC_Product(
                [
                    'id'          => $id,
                    'status'      => 'publish',
                    'is_visible'  => $id >= $visible_from,
                    'is_in_stock' => true,
                ]
            );
        }
        $bought = new \WC_Product(
            [
                'id'             => 1,
                'cross_sell_ids' => $suggested,
            ]
        );
        return new \WC_Order(
            [
                'line_items' => [
                    new \WC_Order_Item_Product(
                        [
                            'product'    => $bought,
                            'product_id' => 1,
                        ]
                    ),
                ],
            ]
        );
    }

    public function test_for_order_primes_the_lines_then_one_chunk_of_suggestions_when_it_is_enough(): void {
        $shown = OrderGrid::for_order( self::order_suggesting( range( 100, 139 ), 102 ), OrderGrid::CROSS_SELLS );

        $this->assertSame( [ [ 1 ], range( 100, 111 ) ], $GLOBALS['yaymatik_test_primed'] );
        $this->assertSame( [ 102, 103, 104, 105 ], array_map( fn( $product ) => $product->get_id(), $shown ), 'hidden ones skipped, at most MAX' );
    }

    public function test_for_order_reads_further_chunks_while_too_few_can_be_shown_up_to_a_cap(): void {
        $shown = OrderGrid::for_order( self::order_suggesting( range( 100, 199 ), 130 ), OrderGrid::CROSS_SELLS );

        $this->assertSame( [ [ 1 ], range( 100, 111 ), range( 112, 123 ), range( 124, 135 ) ], $GLOBALS['yaymatik_test_primed'] );
        $this->assertSame( [ 130, 131, 132, 133 ], array_map( fn( $product ) => $product->get_id(), $shown ) );

        $GLOBALS['yaymatik_test_primed'] = [];
        $this->assertSame( [], OrderGrid::for_order( self::order_suggesting( range( 200, 299 ), 250 ), OrderGrid::CROSS_SELLS ), 'nothing shown past the cap' );
        $this->assertCount( 4, $GLOBALS['yaymatik_test_primed'], 'the lines, then 3 chunks at most' );
    }
}
