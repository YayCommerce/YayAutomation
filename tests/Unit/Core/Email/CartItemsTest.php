<?php
namespace YayMatik\Tests\Unit\Core\Email;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Cart\SavedCart;
use YayMatik\Workflow\Core\Email\CartItems;

require_once dirname( __DIR__, 3 ) . '/Support/woocommerce-stubs.php';

/** The cart block lists a bounded number of lines and loads their products together. */
final class CartItemsTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_primed'] );
    }

    /**
     * @param int $count
     */
    private static function cart( int $count, int $last_quantity = 1 ): SavedCart {
        $lines = array_map(
            fn( $i ) => [
                'product_id' => $i,
                'name'       => "Item {$i}",
                'quantity'   => $i === $count ? $last_quantity : 1,
                'line_total' => 1,
            ],
            range( 1, $count )
        );
        return new SavedCart(
            [
                'items'    => wp_json_encode( $lines ),
                'total'    => $count,
                'currency' => 'USD',
            ]
        );
    }

    public function test_a_long_cart_lists_the_first_lines_and_counts_the_rest(): void {
        $text = CartItems::render( self::cart( CartItems::MAX_LINES + 5 ), false );

        $this->assertSame( CartItems::MAX_LINES, substr_count( $text, ' × 1 — ' ) );
        $this->assertStringContainsString( "\nand 5 more items\nCart total: ", $text );
        $this->assertSame( [ range( 1, CartItems::MAX_LINES ) ], $GLOBALS['yaymatik_test_primed'], 'only the listed lines, in one call' );

        $html = CartItems::render( self::cart( CartItems::MAX_LINES + 1 ), true );
        $this->assertStringContainsString( '>and 1 more item</td>', $html );

        $this->assertStringContainsString( "\nand 3 more items\n", CartItems::render( self::cart( CartItems::MAX_LINES + 1, 3 ), false ), 'items, not lines' );
    }

    public function test_a_short_cart_lists_every_line_without_a_count(): void {
        $text = CartItems::render( self::cart( 2 ), false );

        $this->assertSame( 2, substr_count( $text, ' × 1 — ' ) );
        $this->assertStringNotContainsString( 'more item', $text );
    }
}
