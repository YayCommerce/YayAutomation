<?php
namespace YayMatik\Tests\Unit\Core\Email;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Email\ProductGrid;

require_once dirname( __DIR__, 3 ) . '/Support/woocommerce-stubs.php';

/** The suggested products block: even rows whatever the images' shapes, short names, text unchanged. */
final class ProductGridTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_images'] = [
            1 => [ 'https://shop.test/book.jpg', 300, 450 ],
            2 => [ 'https://shop.test/banner.jpg', 600, 300 ],
            3 => [ 'https://shop.test/shirt.jpg', 300, 300 ],
            4 => [ 'https://shop.test/tiny.jpg', 80, 40 ],
            5 => [ 'https://shop.test/logo.svg', 0, 0 ],
        ];
    }

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_images'], $GLOBALS['yaymatik_test_products'], $GLOBALS['yaymatik_test_primed'] );
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
        $shown = ProductGrid::for_order( self::order_suggesting( range( 100, 139 ), 102 ), ProductGrid::CROSS_SELLS );

        $this->assertSame( [ [ 1 ], range( 100, 111 ) ], $GLOBALS['yaymatik_test_primed'] );
        $this->assertSame( [ 102, 103, 104, 105 ], array_map( fn( $product ) => $product->get_id(), $shown ), 'hidden ones skipped, at most MAX' );
    }

    public function test_for_order_reads_further_chunks_while_too_few_can_be_shown_up_to_a_cap(): void {
        $shown = ProductGrid::for_order( self::order_suggesting( range( 100, 199 ), 130 ), ProductGrid::CROSS_SELLS );

        $this->assertSame( [ [ 1 ], range( 100, 111 ), range( 112, 123 ), range( 124, 135 ) ], $GLOBALS['yaymatik_test_primed'] );
        $this->assertSame( [ 130, 131, 132, 133 ], array_map( fn( $product ) => $product->get_id(), $shown ) );

        $GLOBALS['yaymatik_test_primed'] = [];
        $this->assertSame( [], ProductGrid::for_order( self::order_suggesting( range( 200, 299 ), 250 ), ProductGrid::CROSS_SELLS ), 'nothing shown past the cap' );
        $this->assertCount( 4, $GLOBALS['yaymatik_test_primed'], 'the lines, then 3 chunks at most' );
    }

    /**
     * @param int    $image_id
     * @param string $name
     */
    private static function product( int $image_id, string $name = 'Mug' ): \WC_Product {
        return new \WC_Product(
            [
                'name'       => $name,
                'image_id'   => $image_id,
                'permalink'  => 'https://shop.test/p/' . $image_id,
                'price_html' => '<span class="amount">&#36;10.00</span>',
            ]
        );
    }

    /**
     * Each image's `width` and `height` attributes, in order.
     *
     * @param string $html
     * @return string[]
     */
    private static function sizes( string $html ): array {
        preg_match_all( '/<img [^>]*width="(\d+)" height="(\d+)"/', $html, $found, PREG_SET_ORDER );
        return array_map( fn( array $size ) => $size[1] . '×' . $size[2], $found );
    }

    public function test_images_fit_a_160_square_with_their_ratio_kept_and_never_grow(): void {
        $html = ProductGrid::render( [ self::product( 1 ), self::product( 2 ), self::product( 3 ), self::product( 4 ) ], true );

        $this->assertSame( [ '107×160', '160×80', '160×160', '80×40' ], self::sizes( $html ) );
        $this->assertSame( 4, substr_count( $html, 'style="width:100%;max-width:160px;margin:0 auto;"><tr><td height="160"' ), 'every image sits in the same square' );
        $this->assertStringNotContainsString( 'object-fit', $html );
        $this->assertStringNotContainsString( 'display:flex', $html );
    }

    public function test_an_image_of_unknown_size_keeps_its_own_shape_within_the_square(): void {
        $html = ProductGrid::render( [ self::product( 5 ) ], true );

        $this->assertSame( [], self::sizes( $html ), 'no forced height' );
        $this->assertStringContainsString( 'width="160" style="display:inline-block;max-width:100%;height:auto;', $html );
    }

    public function test_every_part_sets_its_own_spacing_so_the_store_email_css_cant(): void {
        $html = ProductGrid::render( [ self::product( 3 ) ], true );

        $this->assertStringContainsString( 'height:160px;padding:0;', $html, 'the square cell' );
        $this->assertStringContainsString( 'margin:0;border:0;', $html, 'the image' );
        $this->assertStringContainsString( '<p style="margin:8px 0 2px;', $html, 'the name, in its own paragraph' );
    }

    public function test_columns_squares_and_images_shrink_on_a_narrow_phone_and_keep_their_desktop_size(): void {
        $html = ProductGrid::render( [ self::product( 1 ), self::product( 3 ), self::product( 2 ) ], true );

        $this->assertSame( 2, substr_count( $html, '<td width="50%" style="width:50%;' ), 'two half-width columns' );
        $this->assertSame( 3, substr_count( $html, 'width:100%;max-width:160px;' ), 'squares up to 160px, narrower with the column' );
        $this->assertSame( 3, substr_count( $html, 'style="display:inline-block;max-width:100%;height:auto;' ), 'images shrink inside them' );
        $this->assertSame( [ '107×160', '160×160', '160×80' ], self::sizes( $html ), 'the attributes keep the desktop size' );
        $this->assertStringNotContainsString( 'width="160" align', $html, 'no fixed-width square' );
    }

    public function test_a_product_without_an_image_gets_the_placeholder_in_the_same_square(): void {
        $html = ProductGrid::render( [ self::product( 0 ) ], true );

        $this->assertStringContainsString( 'src="https://shop.test/placeholder.png"', $html );
        $this->assertSame( [ '160×160' ], self::sizes( $html ) );
    }

    public function test_an_odd_last_product_is_centered_in_its_row_without_an_empty_cell(): void {
        $html = ProductGrid::render( [ self::product( 1 ), self::product( 2 ), self::product( 3 ) ], true );

        $this->assertSame( 2, substr_count( $html, '<td width="50%"' ), 'the first row holds two' );
        $this->assertStringContainsString( '<td colspan="2"', $html );
        $this->assertStringContainsString( 'width="50%" align="center"', $html );
        $this->assertDoesNotMatchRegularExpression( '/<td[^>]*>\s*<\/td>/', $html );
        $this->assertStringContainsString( 'style="width:100%;margin:0 0 16px;"', $html, 'as wide and spaced as the coupon box' );
    }

    public function test_long_names_are_cut_to_60_characters_with_an_ellipsis(): void {
        $long = str_repeat( 'Ergonomic ', 8 ) . 'Chair';
        $html = ProductGrid::render( [ self::product( 3, $long ), self::product( 3, 'Short mug' ) ], true );

        $this->assertStringContainsString( '>' . rtrim( mb_substr( $long, 0, 59 ) ) . '…</a>', $html );
        $this->assertStringContainsString( '>Short mug</a>', $html );
        $this->assertStringContainsString( 'alt="' . $long . '"', $html, 'the image keeps the full name' );

        $html = ProductGrid::render( [ self::product( 3, str_repeat( 'a', 57 ) . ' &amp; more' ) ], true );
        $this->assertStringContainsString( '>' . str_repeat( 'a', 57 ) . ' &amp;…</a>', $html, 'an entity is cut whole' );
    }

    public function test_text_lists_one_product_per_line_with_the_full_name(): void {
        $long = str_repeat( 'Ergonomic ', 8 ) . 'Chair';
        $this->assertSame(
            $long . " — $10.00 — https://shop.test/p/1\nMug — $10.00 — https://shop.test/p/2",
            ProductGrid::render( [ self::product( 1, $long ), self::product( 2 ) ], false )
        );
        $this->assertSame( '', ProductGrid::render( [], true ) );
    }
}
