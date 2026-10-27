<?php
namespace YayMatik\Tests\Unit\Core\Cart;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Cart\CartSnapshot;

/** The cart hash: what the shopper chose, not what it costs. */
final class CartSnapshotTest extends TestCase {

    public function test_hash_ignores_line_and_attribute_order(): void {
        $a = $this->snapshot(
            [
                $this->line( 10, 0, 1 ),
                $this->line(
                    20,
                    21,
                    2,
                    [
                        'attribute_size'  => 'M',
                        'attribute_color' => 'red',
                    ]
                ),
            ]
        );
        $b = $this->snapshot(
            [
                $this->line(
                    20,
                    21,
                    2,
                    [
                        'attribute_color' => 'red',
                        'attribute_size'  => 'M',
                    ]
                ),
                $this->line( 10, 0, 1 ),
            ]
        );

        $this->assertSame( $a->hash(), $b->hash() );
    }

    public function test_hash_changes_with_quantity_variation_and_currency(): void {
        $base = $this->snapshot( [ $this->line( 20, 21, 2, [ 'attribute_size' => 'M' ] ) ] )->hash();

        $this->assertNotSame( $base, $this->snapshot( [ $this->line( 20, 21, 3, [ 'attribute_size' => 'M' ] ) ] )->hash() );
        $this->assertNotSame( $base, $this->snapshot( [ $this->line( 20, 22, 2, [ 'attribute_size' => 'M' ] ) ] )->hash() );
        $this->assertNotSame( $base, $this->snapshot( [ $this->line( 20, 21, 2, [ 'attribute_size' => 'L' ] ) ] )->hash() );
        $this->assertNotSame( $base, $this->snapshot( [ $this->line( 20, 21, 2, [ 'attribute_size' => 'M' ] ) ], 'EUR' )->hash() );
    }

    public function test_hash_ignores_prices_names_and_totals(): void {
        $line    = $this->line( 10, 0, 1 );
        $changed = [
            'name'       => 'Renamed',
            'price'      => 99.0,
            'line_total' => 99.0,
        ] + $line;

        $this->assertSame(
            $this->snapshot( [ $line ] )->hash(),
            CartSnapshot::from_lines( [ $changed ], 123.45, 'USD' )->hash()
        );
    }

    public function test_from_lines_keeps_lines_total_and_currency(): void {
        $snapshot = CartSnapshot::from_lines( [ 3 => $this->line( 10, 0, 1 ) ], 12.5, 'USD' );

        $this->assertSame( [ $this->line( 10, 0, 1 ) ], $snapshot->lines() );
        $this->assertSame( 12.5, $snapshot->total() );
        $this->assertSame( 'USD', $snapshot->currency() );
    }

    public function test_line_total_is_the_subtotal_before_coupons_with_tax_when_shown(): void {
        $item = [
            'quantity'          => 2,
            'line_subtotal'     => 20.0,
            'line_subtotal_tax' => 2.0,
            'line_total'        => 15.0,
            'line_tax'          => 1.5,
        ];

        $this->assertSame( 20.0, CartSnapshot::line_total( $item, false ) );
        $this->assertSame( 22.0, CartSnapshot::line_total( $item, true ) );
    }

    /**
     * @param array[] $lines
     * @param string  $currency
     */
    private function snapshot( array $lines, string $currency = 'USD' ): CartSnapshot {
        return CartSnapshot::from_lines( $lines, 10.0, $currency );
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
            'price'        => 5.0,
            'line_total'   => 5.0 * $quantity,
        ];
    }
}
