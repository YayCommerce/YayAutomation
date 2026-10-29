<?php
namespace YayMatik\Tests\Unit\Core\Cart;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Cart\CartMerge;
use YayMatik\Workflow\Core\Cart\CartSnapshot;

/** What a restore adds: missing lines, the larger quantity; unavailable lines skipped. */
final class CartMergeTest extends TestCase {

    public function test_missing_lines_are_added_with_their_quantity(): void {
        $saved = [ $this->line( 10, 0, 2 ), $this->line( 20, 21, 1, [ 'attribute_size' => 'M' ] ) ];

        $plan = CartMerge::plan( $saved, [], $this->available( $saved ) );

        $this->assertSame(
            [
                [
                    'line'     => $saved[0],
                    'quantity' => 2,
                ],
                [
                    'line'     => $saved[1],
                    'quantity' => 1,
                ],
            ],
            $plan['add']
        );
        $this->assertSame( 0, $plan['kept'] );
        $this->assertSame( 0, $plan['skipped'] );
    }

    public function test_the_larger_quantity_wins(): void {
        $saved = [ $this->line( 10, 0, 3 ), $this->line( 11, 0, 1 ) ];

        $plan = CartMerge::plan( $saved, [ $this->line( 10, 0, 1 ), $this->line( 11, 0, 4 ) ], $this->available( $saved ) );

        $this->assertSame(
            [
                [
                    'line'     => $saved[0],
                    'quantity' => 2,
                ],
            ],
            $plan['add']
        );
        $this->assertSame( 1, $plan['kept'] );
    }

    public function test_an_equal_quantity_adds_nothing(): void {
        $saved = [ $this->line( 10, 0, 2 ) ];

        $plan = CartMerge::plan( $saved, [ $this->line( 10, 0, 2 ) ], $this->available( $saved ) );

        $this->assertSame( [], $plan['add'] );
        $this->assertSame( 1, $plan['kept'] );
    }

    public function test_the_line_key_holds_variation_and_attributes_in_any_order(): void {
        $saved = [
            $this->line(
                20,
                21,
                2,
                [
                    'attribute_size'  => 'M',
                    'attribute_color' => 'red',
                ]
            ),
        ];
        $same  = $this->line(
            20,
            21,
            2,
            [
                'attribute_color' => 'red',
                'attribute_size'  => 'M',
            ]
        );

        $this->assertSame( [], CartMerge::plan( $saved, [ $same ], $this->available( $saved ) )['add'] );
        $this->assertCount( 1, CartMerge::plan( $saved, [ $this->line( 20, 22, 2, [ 'attribute_size' => 'M' ] ) ], $this->available( $saved ) )['add'] );
        $this->assertCount( 1, CartMerge::plan( $saved, [ $this->line( 20, 21, 2, [ 'attribute_size' => 'L' ] ) ], $this->available( $saved ) )['add'] );
    }

    public function test_unavailable_lines_are_skipped(): void {
        $saved     = [ $this->line( 10, 0, 1 ), $this->line( 11, 0, 1 ) ];
        $available = [ CartSnapshot::line_key( $saved[0] ) => true ];

        $plan = CartMerge::plan( $saved, [], $available );

        $this->assertCount( 1, $plan['add'] );
        $this->assertSame( 10, $plan['add'][0]['line']['product_id'] );
        $this->assertSame( 1, $plan['skipped'] );
    }

    public function test_every_available_line_already_in_the_cart_leaves_nothing_to_do(): void {
        $saved     = [ $this->line( 10, 0, 1 ), $this->line( 11, 0, 1 ) ];
        $available = [
            CartSnapshot::line_key( $saved[0] ) => true,
            CartSnapshot::line_key( $saved[1] ) => false,
        ];

        $plan = CartMerge::plan( $saved, [ $this->line( 10, 0, 5 ) ], $available );

        $this->assertSame( [], $plan['add'] );
        $this->assertSame( 1, $plan['kept'] );
        $this->assertSame( 1, $plan['skipped'] );
    }

    public function test_every_line_unavailable_adds_and_keeps_nothing(): void {
        $saved = [ $this->line( 10, 0, 1 ), $this->line( 11, 0, 1 ) ];

        $plan = CartMerge::plan( $saved, [ $this->line( 10, 0, 1 ) ], [] );

        $this->assertSame( [], $plan['add'] );
        $this->assertSame( 0, $plan['kept'] );
        $this->assertSame( 2, $plan['skipped'] );
    }

    public function test_one_product_twice_counts_as_one_line(): void {
        $saved = [ $this->line( 10, 0, 1 ), $this->line( 10, 0, 2 ) ];

        $plan = CartMerge::plan( $saved, [ $this->line( 10, 0, 1 ), $this->line( 10, 0, 1 ) ], $this->available( $saved ) );

        $this->assertCount( 1, $plan['add'] );
        $this->assertSame( 1, $plan['add'][0]['quantity'] );
    }

    /**
     * @param array[] $lines
     */
    private function available( array $lines ): array {
        $available = [];
        foreach ( $lines as $line ) {
            $available[ CartSnapshot::line_key( $line ) ] = true;
        }
        return $available;
    }

    /**
     * @param int   $product_id
     * @param int   $variation_id
     * @param int   $quantity
     * @param array $variation
     */
    private function line( int $product_id, int $variation_id, int $quantity, array $variation = [] ): array {
        return [
            'product_id'   => $product_id,
            'variation_id' => $variation_id,
            'quantity'     => $quantity,
            'variation'    => $variation,
            'name'         => 'Product ' . $product_id,
            'price'        => 10.0,
            'line_total'   => 10.0 * $quantity,
        ];
    }
}
