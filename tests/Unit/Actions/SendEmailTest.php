<?php
namespace YayMatik\Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeMailer;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;
use YayMatik\Workflow\Actions\SendEmail;
use YayMatik\Workflow\Core\Steps\StepResult;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__, 2 ) . '/Support/fake-wc.php';

/** Send email with the mailer and the unsubscribe list faked. */
final class SendEmailTest extends TestCase {

    /** @var string[] Unsubscribed addresses. */
    private $unsubscribed = [];

    protected function setUp(): void {
        FakeMailer::$current = new FakeMailer();
    }

    private function step(): SendEmail {
        return new SendEmail( new UnsubscribeLink( 'secret' ), fn( string $email ) => in_array( $email, $this->unsubscribed, true ) );
    }

    private function scope( string $email = 'Ann@X.com' ): Scope {
        return new Scope(
            [
                'trigger' => [
                    'customer' => [
                        'email'      => $email,
                        'first_name' => 'Ann <b>&',
                    ],
                ],
            ]
        );
    }

    private function config( array $values = [] ): array {
        return $this->step()->sanitize(
            $values + [
                'to'      => '{{ customer.email }}',
                'subject' => 'Thanks, {{ customer.first_name }}!',
                'heading' => '',
                'message' => '<p>Hi {{ customer.first_name }}</p>',
            ]
        );
    }

    private function run_step( array $config, ?Scope $scope = null ): StepResult {
        $this->assertTrue( $this->step()->validate( $config ), 'config is valid' );
        return $this->step()->run( $scope ?? $this->scope(), $config );
    }

    public function test_sends_to_the_final_address_in_the_woocommerce_wrapper(): void {
        $result = $this->run_step( $this->config() );

        $this->assertTrue( $result->is_ok() );
        $this->assertSame( [ 'to' => 'ann@x.com' ], $result->outputs(), 'no subject or body' );
        list( $to, $subject, $body ) = FakeMailer::$current->sent[0];
        $this->assertSame( 'ann@x.com', $to );
        $this->assertSame( 'Thanks, Ann <b>&!', $subject, 'plain text; a value is never stripped' );
        $this->assertStringStartsWith( '<h1>Thanks, Ann &lt;b&gt;&amp;!</h1><p>Hi Ann &lt;b&gt;&amp;</p>', $body, 'blank heading = the subject' );
    }

    public function test_the_footer_link_and_the_one_click_headers(): void {
        $this->run_step( $this->config() );

        $url = ( new UnsubscribeLink( 'secret' ) )->url( 'ann@x.com' );
        $this->assertStringEndsWith(
            '<p style="margin:32px 0 0;font-size:12px;text-align:center;"><a href="' . esc_url( $url ) . '">Unsubscribe</a></p>',
            FakeMailer::$current->sent[0][2]
        );
        $this->assertSame(
            "Content-Type: text/html\r\nList-Unsubscribe: <{$url}>\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n",
            FakeMailer::$current->headers[0]
        );
        $this->assertStringContainsString( '&yaymatik_token=', FakeMailer::$current->headers[0], 'a raw URL in the header' );
    }

    public function test_a_preview_link_goes_nowhere_and_has_no_unsubscribe_headers(): void {
        $email = $this->step()->compose( $this->config(), $this->scope(), null );

        $this->assertStringEndsWith( '<a href="#">Unsubscribe</a></p>', $email['body'], 'same look, no token' );
        $this->assertStringNotContainsString( 'yaymatik_token', $email['body'] );
        $this->assertSame( "Content-Type: text/html\r\n", $email['headers'] );
        $this->assertSame( 'ann@x.com', SendEmail::recipient( $this->config(), $this->scope() ) );
    }

    public function test_heading_is_plain_text_with_variables(): void {
        $this->run_step( $this->config( [ 'heading' => 'Hello <i>{{ customer.first_name }}</i>' ] ) );

        $this->assertStringStartsWith( '<h1>Hello Ann &lt;b&gt;&amp;</h1>', FakeMailer::$current->sent[0][2], 'typed tags stripped, the value kept' );
        $this->assertSame( 'Thanks, Ann <b>&!', FakeMailer::$current->sent[0][1] );
    }

    public function test_only_real_tags_are_stripped_from_subject_and_heading(): void {
        $line = fn( string $text ) => SendEmail::plain_line( $text, $this->scope() );

        $this->assertSame( 'Save <30% today', $line( 'Save <30% today' ) );
        $this->assertSame( 'a < b > c', $line( 'a < b > c' ) );
        $this->assertSame( 'Hi Ann <b>& friends', $line( '<p>Hi <b>{{ customer.first_name }}</b> friends</p>' ), 'values are plain text, never stripped' );
        $this->assertSame( 'One two', $line( "One<br/>\ntwo" ) );
        $this->assertSame( 'Tom & Jerry', $line( 'Tom &amp; Jerry' ) );
    }

    public function test_values_with_line_breaks_stay_on_one_line(): void {
        $scope = new Scope(
            [
                'trigger' => [
                    'customer' => [
                        'email'      => 'ann@x.com',
                        'first_name' => "Ann\r\nBcc: evil@x.com\n\tX",
                    ],
                ],
            ]
        );

        $this->assertSame( 'Hi Ann Bcc: evil@x.com X', SendEmail::plain_line( 'Hi {{ customer.first_name }}', $scope ) );
        $this->assertSame( 'Hi there', SendEmail::plain_line( "Hi\nthere", $scope ), 'typed line breaks too' );

        $this->run_step( $this->config( [ 'heading' => '{{ customer.first_name }}' ] ), $scope );
        $this->assertSame( 'Thanks, Ann Bcc: evil@x.com X!', FakeMailer::$current->sent[0][1] );
        $this->assertStringStartsWith( '<h1>Ann Bcc: evil@x.com X</h1>', FakeMailer::$current->sent[0][2] );
    }

    public function test_an_unsubscribed_address_skips_the_step(): void {
        $this->unsubscribed = [ 'ann@x.com' ];
        $result             = $this->run_step( $this->config() );

        $this->assertTrue( $result->is_skipped() );
        $this->assertSame( 'unsubscribed', $result->message() );
        $this->assertSame( [], FakeMailer::$current->sent );
    }

    public function test_the_unsubscribe_check_uses_the_final_to_address_not_the_billing_email(): void {
        $this->unsubscribed = [ 'ann@x.com' ];
        $result             = $this->run_step( $this->config( [ 'to' => 'Other@X.com' ] ) );

        $this->assertTrue( $result->is_ok(), 'the billing email is unsubscribed, the address it goes to isn’t' );
        $this->assertSame( 'other@x.com', FakeMailer::$current->sent[0][0] );

        $this->unsubscribed = [ 'other@x.com' ];
        $this->assertTrue( $this->run_step( $this->config( [ 'to' => 'other@x.com' ] ) )->is_skipped() );
    }

    public function test_no_valid_final_address_fails(): void {
        foreach ( [ '', 'not an email' ] as $email ) {
            $result = $this->run_step( $this->config(), $this->scope( $email ) );

            $this->assertTrue( $result->is_failed(), $email );
            $this->assertSame( 'No valid email address.', $result->message() );
            $this->assertSame( 'yaymatik_no_email', $result->code() );
        }
        $this->assertSame( [], FakeMailer::$current->sent );
    }

    public function test_a_mail_that_is_not_sent_fails(): void {
        FakeMailer::$current->succeeds = false;
        $result                        = $this->run_step( $this->config() );

        $this->assertTrue( $result->is_failed() );
        $this->assertSame( 'Couldn’t send the email.', $result->message() );
    }

    public function test_to_is_one_address_or_a_variable(): void {
        $error = fn( string $to ) => $this->step()->validate( $this->config( [ 'to' => $to ] ) );

        $this->assertTrue( $error( 'ann@x.com' ) );
        $this->assertTrue( $error( '{{ customer.email }}' ) );
        $this->assertSame( 'Enter one email address or a variable.', $error( 'a@x.com, b@x.com' )->get_error_message() );
        $this->assertSame( 'Enter one email address or a variable.', $error( 'nope' )->get_error_message() );
        $this->assertSame( 'Send email needs an email address.', $error( '' )->get_error_message() );
    }

    public function test_a_new_step_starts_with_the_customer_email(): void {
        $to = array_values( array_filter( $this->step()->fields(), fn( $field ) => 'to' === $field->key() ) )[0];

        $this->assertSame( '{{ customer.email }}', $to->to_array()['default'] );
    }
}
