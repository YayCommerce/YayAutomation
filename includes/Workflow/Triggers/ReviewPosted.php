<?php
namespace YayMatik\Workflow\Triggers;

use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Steps\Trigger;
use YayMatik\Workflow\DataItems\CustomerData;
use YayMatik\Workflow\DataItems\ReviewData;

defined( 'ABSPATH' ) || exit;

/** A top-level product review is approved: posted approved, or approved later. Once per review and workflow. */
class ReviewPosted extends Trigger {

    public const ID = 'review_posted';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Review posted', 'yaymatik' );
    }

    public function description(): string {
        return __( 'A product review is approved', 'yaymatik' );
    }

    public function icon(): string {
        return 'star';
    }

    public function category(): string {
        return 'review';
    }

    public function fields(): array {
        return [];
    }

    public function summary(): string {
        return __( 'When a product review is approved', 'yaymatik' );
    }

    public function provides(): array {
        return [ ReviewData::ID, CustomerData::ID ];
    }

    public function claims_per_workflow(): bool {
        return true;
    }

    public function subject( array $args ): string {
        return 'review:' . (int) ( $args['review_id'] ?? 0 );
    }

    /**
     * Rating and "verified" are saved after `wp_insert_comment`, so only the id is taken here; the rest is read at shutdown.
     *
     * @param callable $emit
     */
    public function listen( callable $emit ): void {
        $report = function ( $comment ) use ( $emit ) {
            if ( ReviewData::is_review( $comment ) ) {
                $emit( [ 'review_id' => (int) $comment->comment_ID ] );
            }
        };
        add_action(
            'wp_insert_comment',
            function ( $comment_id, $comment ) use ( $report ) {
                if ( $comment instanceof \WP_Comment && '1' === (string) $comment->comment_approved ) {
                    $report( $comment );
                }
            },
            10,
            2
        );
        add_action(
            'transition_comment_status',
            function ( $new_status, $old_status, $comment ) use ( $report ) {
                // Approved from moderation only: restoring from trash or spam brings back a review approved before.
                if ( 'approved' === $new_status && 'unapproved' === $old_status ) {
                    $report( $comment );
                }
            },
            10,
            3
        );
    }

    /**
     * Runs at the end of the request: the review must still be approved.
     *
     * @param array $args
     */
    public function claim( array $args ): bool {
        $review = ReviewData::find( absint( $args['review_id'] ?? 0 ) );
        return $review && 'approved' === wp_get_comment_status( $review );
    }

    public function context( array $args ): ?array {
        $review   = ReviewData::find( absint( $args['review_id'] ?? 0 ) );
        $customer = $review ? RefResolver::reviewer( $review ) : null;
        return $customer ? [
            ReviewData::ID   => $review,
            CustomerData::ID => $customer,
        ] : null;
    }

    public function refs( array $args ): ?array {
        $review = ReviewData::find( absint( $args['review_id'] ?? 0 ) );
        if ( ! $review ) {
            return null;
        }
        $id = (int) $review->comment_ID;
        return [
            ReviewData::ID   => new Ref( ReviewData::ID, $id ),
            CustomerData::ID => new Ref( CustomerData::ID, ReviewData::ID . ':' . $id ),
        ];
    }
}
