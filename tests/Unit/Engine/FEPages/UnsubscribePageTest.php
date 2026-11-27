<?php
namespace YayMatik\Tests\Unit\Engine\FEPages;

use PHPUnit\Framework\TestCase;
use YayMatik\Engine\FEPages\UnsubscribePage;

/** What an unsubscribe request may do: the button needs its nonce, a mail app's one-click only the signed link. */
final class UnsubscribePageTest extends TestCase {

    private const EMAIL = 'ann@example.test';

    public function test_a_link_whose_token_fails_is_invalid_even_as_one_click(): void {
        $this->assertSame( 'invalid', UnsubscribePage::request( null, true, [ 'List-Unsubscribe' => 'One-Click' ] ) );
        $this->assertSame( 'invalid', UnsubscribePage::request( null, false, [] ) );
    }

    public function test_get_only_shows_the_button(): void {
        $this->assertSame( 'confirm', UnsubscribePage::request( self::EMAIL, false, [ '_wpnonce' => $this->nonce() ] ) );
    }

    public function test_the_button_unsubscribes_with_its_nonce(): void {
        $this->assertSame( UnsubscribePage::UNSUBSCRIBE, UnsubscribePage::request( self::EMAIL, true, [ '_wpnonce' => $this->nonce() ] ) );
    }

    /**
     * @dataProvider posts_without_a_valid_nonce
     * @param array $body
     */
    public function test_a_post_without_a_valid_nonce_shows_the_button_again( array $body ): void {
        $this->assertSame( 'confirm', UnsubscribePage::request( self::EMAIL, true, $body ) );
    }

    public function posts_without_a_valid_nonce(): array {
        return [
            'no nonce'                 => [ [] ],
            'empty nonce'              => [ [ '_wpnonce' => '' ] ],
            'wrong nonce'              => [ [ '_wpnonce' => 'nonce:something_else' ] ],
            'another address\'s nonce' => [ [ '_wpnonce' => 'nonce:yaymatik_unsubscribe_' . md5( 'bob@example.test' ) ] ],
            'one-click, other value'   => [ [ 'List-Unsubscribe' => 'yes' ] ],
        ];
    }

    public function test_a_mail_apps_one_click_needs_only_the_signed_link(): void {
        $this->assertSame( UnsubscribePage::UNSUBSCRIBE, UnsubscribePage::request( self::EMAIL, true, [ 'List-Unsubscribe' => 'One-Click' ] ) );
    }

    private function nonce(): string {
        return 'nonce:yaymatik_unsubscribe_' . md5( self::EMAIL );
    }
}
