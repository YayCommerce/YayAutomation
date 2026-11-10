<?php
namespace YayMatik\Workflow\DataItems;

use YayMatik\Workflow\Core\Data\DataItem;

defined( 'ABSPATH' ) || exit;

/** A product review (\WP_Comment of type `review`), supplied by Review posted. */
final class ReviewData extends DataItem {

    public const ID = 'review';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Review', 'yaymatik' );
    }

    public function description(): string {
        return __( 'The product review that started the run: rating, text and product.', 'yaymatik' );
    }

    public function icon(): string {
        return 'star';
    }

    protected function define(): array {
        return [
            'rating'       => [
                'label' => __( 'Rating', 'yaymatik' ),
                'type'  => self::TYPE_NUMBER,
                // A review posted without stars has no rating, which no number rule matches.
                'value' => function ( \WP_Comment $review ) {
                    $rating = get_comment_meta( (int) $review->comment_ID, 'rating', true );
                    return '' === $rating ? null : (float) $rating;
                },
            ],
            'content'      => [
                'label' => __( 'Review text', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( \WP_Comment $review ) => (string) $review->comment_content,
            ],
            'is_verified'  => [
                'label' => __( 'Verified owner', 'yaymatik' ),
                'type'  => self::TYPE_BOOLEAN,
                'value' => fn( \WP_Comment $review ) => (bool) get_comment_meta( (int) $review->comment_ID, 'verified', true ),
            ],
            'product_name' => [
                'label' => __( 'Reviewed product', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => function ( \WP_Comment $review ) {
                    $product = wc_get_product( (int) $review->comment_post_ID );
                    return $product ? (string) $product->get_name() : '';
                },
            ],
        ];
    }

    /**
     * Whether this is a top-level product review.
     *
     * @param mixed $comment
     */
    public static function is_review( $comment ): bool {
        return $comment instanceof \WP_Comment && 'review' === $comment->comment_type && 0 === (int) $comment->comment_parent;
    }

    /**
     * The review with this id, while it exists.
     *
     * @param int $review_id
     */
    public static function find( int $review_id ): ?\WP_Comment {
        $review = get_comment( $review_id );
        return self::is_review( $review ) ? $review : null;
    }

    public function sample(): array {
        return [
            'rating'       => 5.0,
            'content'      => __( 'Great quality, and it arrived fast.', 'yaymatik' ),
            'is_verified'  => true,
            'product_name' => __( 'Sample product', 'yaymatik' ),
        ];
    }
}
