<?php
namespace YayMatik\Tests\Unit\DataItems;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\DataItems\ReviewData;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** A review's fields, read from the comment and its meta. */
final class ReviewDataTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_comment_rows'], $GLOBALS['yaymatik_test_products'] );
    }

    /**
     * @param string      $field
     * @param \WP_Comment $review
     * @return mixed
     */
    private static function value( string $field, \WP_Comment $review ) {
        return ( new ReviewData() )->fields()[ $field ]['value']( $review );
    }

    public function test_fields(): void {
        $review                                   = new \WP_Comment(
            [
                'comment_ID'      => '8',
                'comment_post_ID' => '30',
                'comment_type'    => 'review',
                'comment_content' => 'Lovely mug',
                'meta'            => [
                    'rating'   => '4',
                    'verified' => '1',
                ],
            ]
        );
        $GLOBALS['yaymatik_test_comment_rows'][8] = $review;
        $GLOBALS['yaymatik_test_products'][30]    = new \WC_Product( [ 'name' => 'Mug' ] );

        $this->assertSame( 4.0, self::value( 'rating', $review ) );
        $this->assertSame( 'Lovely mug', self::value( 'content', $review ) );
        $this->assertTrue( self::value( 'is_verified', $review ) );
        $this->assertSame( 'Mug', self::value( 'product_name', $review ) );
    }

    public function test_no_rating_unverified_and_a_gone_product(): void {
        $review                                   = new \WP_Comment( [ 'comment_ID' => '9' ] );
        $GLOBALS['yaymatik_test_comment_rows'][9] = $review;

        $this->assertNull( self::value( 'rating', $review ), 'no stars: no rating, not 0' );
        $this->assertFalse( self::value( 'is_verified', $review ) );
        $this->assertSame( '', self::value( 'product_name', $review ) );
    }

    public function test_is_review_needs_a_top_level_review(): void {
        $this->assertTrue( ReviewData::is_review( new \WP_Comment( [ 'comment_type' => 'review' ] ) ) );
        $this->assertFalse(
            ReviewData::is_review(
                new \WP_Comment(
                    [
                        'comment_type'   => 'review',
                        'comment_parent' => '3',
                    ]
                )
            )
        );
        $this->assertFalse( ReviewData::is_review( new \WP_Comment( [] ) ) );
        $this->assertFalse( ReviewData::is_review( null ) );
    }
}
