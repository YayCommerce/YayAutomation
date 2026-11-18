<?php
namespace YayMatik\Tests\Unit\Pro\Workflow\Actions;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeMailer;
use YayMatik\Tests\Support\FakeOrder;
use YayMatik\Tests\Support\FakeWcEmail;
use YayMatik\Pro\Workflow\Actions\ResendWcEmail;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Data\Scope;

require_once dirname( __DIR__, 4 ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__, 4 ) . '/Support/fake-wc.php';

/** Resend WooCommerce email: which emails it offers, and when it sends. */
final class ResendWcEmailTest extends TestCase {

    /** @var callable|null */
    private $filter = null;

    protected function setUp(): void {
        FakeMailer::$current         = new FakeMailer();
        FakeMailer::$current->emails = [
            'WC_Email_Customer_Completed_Order' => new FakeWcEmail( 'customer_completed_order', 'Completed order' ),
            'WC_Email_Customer_Invoice'         => new FakeWcEmail( 'customer_invoice', 'Order details' ),
            'WC_Email_Customer_Refunded_Order'  => new FakeWcEmail( 'customer_refunded_order', 'Refunded order' ),
            'WC_Email_Customer_Note'            => new FakeWcEmail( 'customer_note', 'Customer note' ),
            'WC_Email_New_Order'                => new FakeWcEmail( 'new_order', 'New order', false ),
            'Other_Email'                       => new FakeWcEmail( 'other_shipped', 'Shipped' ),
        ];
    }

    protected function tearDown(): void {
        if ( $this->filter ) {
            remove_filter( 'yaymatik_resendable_emails', $this->filter );
        }
    }

    /**
     * @param callable $filter
     */
    private function filter( callable $filter ): void {
        $this->filter = $filter;
        add_filter( 'yaymatik_resendable_emails', $filter );
    }

    /**
     * Runs a resend of this email for order 12.
     *
     * @param string $id
     * @param string $billing_email
     */
    private function resend( string $id, string $billing_email = 'ann@x.com' ) {
        $order = new FakeOrder(
            [
                'id'            => 12,
                'billing_email' => $billing_email,
            ]
        );
        $scope = new Scope( [ 'trigger' => [ 'order' => new Ref( 'order', 12 ) ] ], new RefResolver( [ 'order' => fn() => $order ] ) );
        $step  = new ResendWcEmail();
        return $step->run( $scope, $step->sanitize( [ 'email' => $id ] ) );
    }

    private function email( string $class_name ): FakeWcEmail {
        return FakeMailer::$current->emails[ $class_name ];
    }

    public function test_offers_the_allowed_customer_emails_this_store_has(): void {
        $this->assertSame(
            [
                'customer_completed_order' => 'Completed order',
                'customer_invoice'         => 'Order details',
            ],
            ResendWcEmail::emails()
        );
    }

    public function test_the_filter_adds_and_removes_ids_but_admin_emails_stay_out(): void {
        $this->filter( fn( array $ids ) => array_merge( array_diff( $ids, [ 'customer_invoice' ] ), [ 'other_shipped', 'new_order', 'not_registered' ] ) );
        $this->assertSame(
            [
                'customer_completed_order' => 'Completed order',
                'other_shipped'            => 'Shipped',
            ],
            ResendWcEmail::emails()
        );
    }

    public function test_sends_through_woocommerce_and_outputs_only_the_id(): void {
        $result = $this->resend( 'customer_completed_order' );
        $this->assertTrue( $result->is_ok() );
        $this->assertSame( [ 'email' => 'customer_completed_order' ], $result->outputs() );
        $this->assertSame( [ 12 ], $this->email( 'WC_Email_Customer_Completed_Order' )->triggered );
    }

    public function test_an_email_turned_off_or_an_order_without_an_email_is_skipped(): void {
        $this->email( 'WC_Email_Customer_Completed_Order' )->enabled = false;
        $result = $this->resend( 'customer_completed_order' );
        $this->assertTrue( $result->is_skipped() );
        $this->assertSame( 'Completed order is turned off in WooCommerce.', $result->message() );

        $result = $this->resend( 'customer_invoice', '' );
        $this->assertTrue( $result->is_skipped() );
        $this->assertSame( [], $this->email( 'WC_Email_Customer_Invoice' )->triggered );
    }

    public function test_an_email_no_longer_allowed_fails(): void {
        foreach ( [ 'customer_refunded_order', 'new_order', 'gone' ] as $id ) {
            $this->assertTrue( $this->resend( $id )->is_failed(), $id );
        }
        $this->assertSame( [], $this->email( 'WC_Email_Customer_Refunded_Order' )->triggered );
    }

    public function test_an_unknown_email_is_an_issue_at_save(): void {
        $step = new ResendWcEmail();
        $this->assertInstanceOf( \WP_Error::class, $step->validate( $step->sanitize( [ 'email' => 'customer_refunded_order' ] ) ) );
        $this->assertTrue( $step->validate( $step->sanitize( [ 'email' => 'customer_invoice' ] ) ) );
    }
}
