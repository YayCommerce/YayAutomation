<?php
namespace YayMatik\Tests\Unit\Core\Email;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Email\ReviewProducts;

require_once dirname( __DIR__, 3 ) . '/Support/woocommerce-stubs.php';

/** The review block's output, and the guards that keep get_comments() from marking everything reviewed. */
final class ReviewProductsTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_comment_queries'] = [];
    }

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_comment_queries'] );
    }

    private static function product(): \WC_Product {
        return new \WC_Product(
            [
                'name'      => 'Mug &amp; Tea',
                'image_id'  => 0,
                'permalink' => 'https://shop.test/p/mug',
            ]
        );
    }

    public function test_no_products_render_nothing(): void {
        $this->assertSame( '', ReviewProducts::render( [], true ) );
        $this->assertSame( '', ReviewProducts::render( [], false ) );
    }

    public function test_html_is_one_line_with_a_small_button_to_the_reviews_tab(): void {
        $html = ReviewProducts::render( [ self::product(), self::product() ], true );

        $this->assertStringNotContainsString( "\n", $html );
        $this->assertSame( 2, substr_count( $html, '<a href="https://shop.test/p/mug#reviews"' ) );
        $this->assertStringContainsString( 'padding:6px 12px;font-size:13px;', $html );
        $this->assertStringContainsString( '>Mug &amp; Tea</a>', $html );
    }

    public function test_text_is_one_line_per_product(): void {
        $this->assertSame(
            "Mug & Tea — Leave a review: https://shop.test/p/mug#reviews\nMug & Tea — Leave a review: https://shop.test/p/mug#reviews",
            ReviewProducts::render( [ self::product(), self::product() ], false )
        );
    }

    public function test_no_products_run_no_query(): void {
        $this->assertSame( [], ReviewProducts::reviewed_ids( [], 'a@b.test', 5 ) );
        $this->assertSame( [], $GLOBALS['yaymatik_test_comment_queries'] );
    }

    public function test_an_empty_email_and_user_zero_run_no_query(): void {
        $this->assertSame( [], ReviewProducts::reviewed_ids( [ 1 ], '', 0 ) );
        $this->assertSame( [], $GLOBALS['yaymatik_test_comment_queries'] );
    }

    public function test_each_identity_runs_its_own_query(): void {
        ReviewProducts::reviewed_ids( [ 1 ], 'a@b.test', 0 );
        ReviewProducts::reviewed_ids( [ 1 ], '', 5 );

        $queries = $GLOBALS['yaymatik_test_comment_queries'];
        $this->assertSame( 'a@b.test', $queries[0]['author_email'] );
        $this->assertArrayNotHasKey( 'user_id', $queries[0] );
        $this->assertSame( 5, $queries[1]['user_id'] );
        $this->assertArrayNotHasKey( 'author_email', $queries[1] );
    }
}
