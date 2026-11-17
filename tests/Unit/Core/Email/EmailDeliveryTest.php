<?php
namespace YayMatik\Tests\Unit\Core\Email;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeMailer;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Values;
use YayMatik\Workflow\Core\Email\EmailDelivery;
use YayMatik\Workflow\Core\Email\EmailStep;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;

require_once dirname( __DIR__, 3 ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__, 3 ) . '/Support/fake-wc.php';

/** Run-time sending for any email step: recipient, unsubscribed, link, send, `to`. */
final class EmailDeliveryTest extends TestCase {

    /** @var string[] */
    private $unsubscribed = [ 'gone@example.test' ];

    /** @var array|null What the step got: [ unsubscribe, test ]. */
    private $composed = null;

    protected function setUp(): void {
        FakeMailer::$current = new FakeMailer();
    }

    private function delivery(): EmailDelivery {
        return new EmailDelivery( new UnsubscribeLink( 'secret' ), fn( string $email ) => in_array( $email, $this->unsubscribed, true ) );
    }

    /**
     * @param string $audience
     * @param string $to
     * @param bool   $raw
     */
    private function step( string $audience, string $to, bool $raw = false ): EmailStep {
        $test = $this;
        return new class( $audience, $to, $raw, $test ) implements EmailStep {
            /** @var string */
            private $audience;
            /** @var string */
            private $to;
            /** @var bool */
            private $raw;
            /** @var EmailDeliveryTest */
            private $test;

            /**
             * @param string            $audience
             * @param string            $to
             * @param bool              $raw
             * @param EmailDeliveryTest $test
             */
            public function __construct( string $audience, string $to, bool $raw, EmailDeliveryTest $test ) {
                $this->audience = $audience;
                $this->to       = $to;
                $this->raw      = $raw;
                $this->test     = $test;
            }

            public function email_audience(): string {
                return $this->audience;
            }

            /**
             * @param array       $config
             * @param Values      $values
             * @param string|null $unsubscribe
             * @param bool        $test
             */
            public function compose_email( array $config, Values $values, ?string $unsubscribe, bool $test = false ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- EmailStep's signature.
                $this->test->composed( $unsubscribe, $test );
                return [
                    'subject' => 'Hi',
                    'heading' => 'Hi',
                    'body'    => '<p>Body</p>',
                    'headers' => "Content-Type: text/html\r\n",
                    'raw'     => $this->raw,
                ];
            }

            /**
             * @param array  $config
             * @param Values $values
             */
            public function email_recipient( array $config, Values $values ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- EmailStep's signature.
                return $this->to;
            }
        };
    }

    /**
     * @param string|null $unsubscribe
     * @param bool        $test
     */
    public function composed( ?string $unsubscribe, bool $test ): void {
        $this->composed = [ $unsubscribe, $test ];
    }

    public function test_a_customer_email_gets_its_unsubscribe_link_and_outputs_to(): void {
        $result = $this->delivery()->deliver( $this->step( 'customer', 'ann@example.test' ), [], new Scope() );

        $this->assertTrue( $result->is_ok() );
        $this->assertSame( [ 'to' => 'ann@example.test' ], $result->outputs() );
        $this->assertSame( [ ( new UnsubscribeLink( 'secret' ) )->url( 'ann@example.test' ), false ], $this->composed );
        $this->assertSame( [ 'ann@example.test', 'Hi', '<p>Body</p>' ], FakeMailer::$current->sent[0] );
    }

    public function test_a_staff_email_has_no_link_and_ignores_the_unsubscribe_list(): void {
        $result = $this->delivery()->deliver( $this->step( 'staff', 'gone@example.test' ), [], new Scope() );

        $this->assertTrue( $result->is_ok() );
        $this->assertSame( [ '', false ], $this->composed, 'a real send without a link, not a preview' );
    }

    public function test_an_unsubscribed_customer_is_skipped_and_nothing_is_built(): void {
        $result = $this->delivery()->deliver( $this->step( 'customer', 'gone@example.test' ), [], new Scope() );

        $this->assertTrue( $result->is_skipped() );
        $this->assertSame( 'The customer unsubscribed from these emails.', $result->message() );
        $this->assertNull( $this->composed );
        $this->assertSame( [], FakeMailer::$current->sent );
    }

    public function test_no_address_or_a_failed_send_fails(): void {
        $none                          = $this->delivery()->deliver( $this->step( 'customer', '' ), [], new Scope() );
        FakeMailer::$current->succeeds = false;
        $down                          = $this->delivery()->deliver( $this->step( 'customer', 'ann@example.test' ), [], new Scope() );

        $this->assertSame( 'No valid email address.', $none->message() );
        $this->assertTrue( $down->is_failed() );
        $this->assertSame( 'Couldn’t send the email.', $down->message() );
    }

    public function test_a_raw_document_is_sent_without_woocommerce_styles_inlined(): void {
        $seen                         = null;
        FakeMailer::$current->on_send = function () use ( &$seen ) {
            $seen = apply_filters( 'woocommerce_mail_style_inline_callback', 'inline', '<p>x</p>', null );
        };

        $this->delivery()->deliver( $this->step( 'customer', 'ann@example.test', true ), [], new Scope() );

        $this->assertIsCallable( $seen, 'kept as built while sending' );
        $this->assertSame( '<p>x</p>', $seen( '<p>x</p>' ) );
        $this->assertSame( 'inline', apply_filters( 'woocommerce_mail_style_inline_callback', 'inline', '', null ), 'removed after' );
    }
}
