<?php
namespace YayMatik\Tests\Unit\Triggers;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Data\EmailCustomer;
use YayMatik\Workflow\Triggers\ReviewPosted;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** Review posted: approved top-level product reviews only, once per review. */
final class ReviewPostedTest extends TestCase {

    /** @var array[] Args emitted. */
    private $emitted = [];

    protected function setUp(): void {
        ( new ReviewPosted() )->listen(
            function ( array $args ) {
                $this->emitted[] = $args;
            }
        );
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['yaymatik_test_filters']['wp_insert_comment'],
            $GLOBALS['yaymatik_test_filters']['transition_comment_status'],
            $GLOBALS['yaymatik_test_comment_rows'],
            $GLOBALS['yaymatik_test_users']
        );
    }

    private static function comment( array $fields = [] ): \WP_Comment {
        return new \WP_Comment(
            $fields + [
                'comment_ID'           => '8',
                'comment_post_ID'      => '30',
                'comment_type'         => 'review',
                'comment_author_email' => 'guest@example.com',
            ]
        );
    }

    /**
     * @return array[] [ comment fields, emits ]
     */
    public static function inserted(): array {
        return [
            'an approved review'   => [ [], true ],
            'held for moderation'  => [ [ 'comment_approved' => '0' ], false ],
            'spam'                 => [ [ 'comment_approved' => 'spam' ], false ],
            'a reply'              => [ [ 'comment_parent' => '5' ], false ],
            'a plain post comment' => [ [ 'comment_type' => 'comment' ], false ],
            'an order note'        => [ [ 'comment_type' => 'order_note' ], false ],
        ];
    }

    /**
     * @dataProvider inserted
     *
     * @param array $fields
     * @param bool  $emits
     */
    public function test_inserted( array $fields, bool $emits ): void {
        do_action( 'wp_insert_comment', 8, self::comment( $fields ) );

        $this->assertSame( $emits ? [ [ 'review_id' => 8 ] ] : [], $this->emitted );
    }

    public function test_approved_later_and_only_then(): void {
        do_action( 'transition_comment_status', 'approved', 'unapproved', self::comment() );
        do_action( 'transition_comment_status', 'spam', 'approved', self::comment() );
        do_action( 'transition_comment_status', 'approved', 'trash', self::comment(), 'restored from trash' );
        do_action( 'transition_comment_status', 'approved', 'spam', self::comment() );
        do_action( 'transition_comment_status', 'approved', 'unapproved', self::comment( [ 'comment_parent' => '5' ] ) );

        $this->assertSame( [ [ 'review_id' => 8 ] ], $this->emitted );
    }

    public function test_claims_per_review_while_it_is_approved(): void {
        $trigger                                  = new ReviewPosted();
        $GLOBALS['yaymatik_test_comment_rows'][8] = self::comment();

        $this->assertTrue( $trigger->claims_per_workflow() );
        $this->assertSame( 'review:8', $trigger->subject( [ 'review_id' => 8 ] ) );
        $this->assertTrue( $trigger->claim( [ 'review_id' => 8 ] ) );

        $GLOBALS['yaymatik_test_comment_rows'][8] = self::comment( [ 'comment_approved' => '0' ] );
        $this->assertFalse( $trigger->claim( [ 'review_id' => 8 ] ), 'unapproved again before shutdown' );
        $this->assertFalse( $trigger->claim( [ 'review_id' => 9 ] ), 'gone' );
    }

    public function test_a_guest_reviewer_is_only_an_email_even_when_an_account_has_it(): void {
        $GLOBALS['yaymatik_test_comment_rows'][8] = self::comment();
        $context                                  = ( new ReviewPosted() )->context( [ 'review_id' => 8 ] );

        $this->assertInstanceOf( EmailCustomer::class, $context['customer'] );
        $this->assertSame( 'guest@example.com', $context['customer']->get_billing_email() );
        $this->assertSame( '', $context['customer']->get_billing_first_name() );
        $this->assertSame( 0, $context['customer']->get_id() );
    }

    public function test_refs_point_at_the_review_and_its_reviewer(): void {
        $GLOBALS['yaymatik_test_comment_rows'][8] = self::comment();
        $trigger                                  = new ReviewPosted();
        $refs                                     = $trigger->refs( [ 'review_id' => 8 ] );

        $this->assertSame( [ 'review', 'customer' ], $trigger->provides() );
        $this->assertSame( 'review', $trigger->category() );
        $this->assertSame( 'review:8', $refs['review']->key() );
        $this->assertSame( 'customer:review:8', $refs['customer']->key() );
        $this->assertNull( $trigger->refs( [] ), 'no review_id' );
        $this->assertNull( $trigger->refs( [ 'review_id' => 9 ] ), 'gone' );
    }
}
