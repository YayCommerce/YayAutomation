<?php
namespace YayMatik\Tests\Unit\Email;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Email\UnsubscribeLink;

/** Signed unsubscribe links. The page and the table are checked on a real site. */
final class UnsubscribeLinkTest extends TestCase {

    /** @var UnsubscribeLink */
    private $link;

    protected function setUp(): void {
        $this->link = new UnsubscribeLink( 'test-secret' );
    }

    /**
     * The query args of a link.
     *
     * @param string $url Link.
     */
    private function args( string $url ): array {
        parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
        return $args;
    }

    public function test_a_link_verifies_back_to_its_normalized_address(): void {
        $args = $this->args( $this->link->url( '  Jane.Doe@Example.COM ' ) );

        $this->assertSame( 'jane.doe@example.com', $this->link->verify( $args[ UnsubscribeLink::ARG_EMAIL ], $args[ UnsubscribeLink::ARG_TOKEN ] ) );
    }

    public function test_the_link_never_holds_the_plain_address(): void {
        $url = $this->link->url( 'jane@example.com' );

        $this->assertStringStartsWith( 'https://shop.test/?', $url );
        $this->assertStringNotContainsString( 'jane', $url );
        $this->assertStringNotContainsString( '%40', $url );
    }

    public function test_case_and_spaces_give_the_same_token(): void {
        $this->assertSame( $this->link->url( 'jane@example.com' ), $this->link->url( ' JANE@example.com' ) );
    }

    public function test_a_changed_token_or_address_is_rejected(): void {
        $args  = $this->args( $this->link->url( 'jane@example.com' ) );
        $email = $args[ UnsubscribeLink::ARG_EMAIL ];
        $token = $args[ UnsubscribeLink::ARG_TOKEN ];

        $this->assertNull( $this->link->verify( $email, $token . 'x' ) );
        $this->assertNull( $this->link->verify( $email, '' ) );
        $this->assertNull( $this->link->verify( UnsubscribeLink::base64url_encode( 'john@example.com' ), $token ) );
    }

    public function test_another_secret_rejects_the_link(): void {
        $args = $this->args( $this->link->url( 'jane@example.com' ) );

        $other = new UnsubscribeLink( 'other-secret' );
        $this->assertNull( $other->verify( $args[ UnsubscribeLink::ARG_EMAIL ], $args[ UnsubscribeLink::ARG_TOKEN ] ) );
    }

    public function test_junk_args_are_rejected(): void {
        $this->assertNull( $this->link->verify( '', '' ) );
        $this->assertNull( $this->link->verify( 'not base64!', 'abc' ) );
        // Valid base64url, but not an email.
        $text = UnsubscribeLink::base64url_encode( 'not-an-email' );
        $this->assertNull( $this->link->verify( $text, $this->link->token( 'not-an-email' ) ) );
    }

    public function test_no_link_for_a_non_email(): void {
        $this->assertSame( '', $this->link->url( '' ) );
        $this->assertSame( '', $this->link->url( '{{ customer.email }}' ) );
    }

    public function test_base64url_round_trips_bytes_that_need_plus_and_slash(): void {
        $bytes   = "\xfb\xff\xfe>?";
        $encoded = UnsubscribeLink::base64url_encode( $bytes );

        $this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $encoded );
        $this->assertSame( $bytes, UnsubscribeLink::base64url_decode( $encoded ) );
        $this->assertNull( UnsubscribeLink::base64url_decode( 'a+b/' ) );
    }

    public function test_normalize(): void {
        $this->assertSame( 'a@b.co', UnsubscribeLink::normalize( ' A@B.co ' ) );
        $this->assertSame( '', UnsubscribeLink::normalize( 'nope' ) );
    }
}
