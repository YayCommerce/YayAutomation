<?php
namespace YayMatik\Tests\Unit\Core\Cart;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Cart\RestoreLink;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;

/** Signed restore links. The handler is checked on a real site (check-carts). */
final class RestoreLinkTest extends TestCase {

    private const CREATED = '2026-10-28 09:15:00';

    /** @var RestoreLink */
    private $link;

    protected function setUp(): void {
        $this->link = new RestoreLink( 'test-secret' );
    }

    /**
     * The query args of a link.
     *
     * @param string $url
     */
    private function args( string $url ): array {
        parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
        return $args;
    }

    public function test_the_link_holds_the_id_and_a_token(): void {
        $url  = $this->link->url( 42, self::CREATED );
        $args = $this->args( $url );

        $this->assertStringStartsWith( 'https://shop.test/?', $url );
        $this->assertSame( [ RestoreLink::ARG_CART, UnsubscribeLink::ARG_TOKEN ], array_keys( $args ) );
        $this->assertSame( '42', $args[ RestoreLink::ARG_CART ] );
        $this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $args[ UnsubscribeLink::ARG_TOKEN ] );
    }

    public function test_a_link_verifies_against_its_row(): void {
        $token = $this->args( $this->link->url( 42, self::CREATED ) )[ UnsubscribeLink::ARG_TOKEN ];

        $this->assertTrue( $this->link->verify( 42, self::CREATED, $token ) );
    }

    public function test_a_changed_token_other_id_or_new_cycle_fails(): void {
        $token   = $this->args( $this->link->url( 42, self::CREATED ) )[ UnsubscribeLink::ARG_TOKEN ];
        $changed = ( 'A' === $token[0] ? 'B' : 'A' ) . substr( $token, 1 );

        $this->assertFalse( $this->link->verify( 42, self::CREATED, $changed ) );
        $this->assertFalse( $this->link->verify( 43, self::CREATED, $token ) );
        $this->assertFalse( $this->link->verify( 42, '2026-10-29 10:00:00', $token ) );
        $this->assertFalse( $this->link->verify( 42, self::CREATED, '' ) );
        $this->assertFalse( ( new RestoreLink( 'other-secret' ) )->verify( 42, self::CREATED, $token ) );
    }
}
