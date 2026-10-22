<?php
namespace YayMatik\Tests\Unit\Core\Email;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeValues;
use YayMatik\Workflow\Core\Email\EmailBody;

/** A stored message as the email body: HTML as written, or plain text, what the editor saves for a message without tags. */
final class EmailBodyTest extends TestCase {

    private const ITEMS = '<table class="td"><tr><td>Mug</td></tr></table>';

    private function render( string $message ): string {
        return EmailBody::render(
            $message,
            new FakeValues(
                [
                    'order.number'        => '1012',
                    'customer.first_name' => 'Ann <b>',
                ],
                [
                    'order.items' => [
                        'text' => 'Mug',
                        'html' => self::ITEMS,
                    ],
                ]
            )
        );
    }

    public function test_html_is_kept_and_values_escaped(): void {
        $this->assertSame(
            '<p>Hi <em>Ann &lt;b&gt;</em></p>' . self::ITEMS . "\n",
            $this->render( '<p>Hi <em>{{ customer.first_name }}</em></p><p>{{ order.items }}</p>' )
        );
    }

    public function test_plain_text_is_escaped_then_split_into_paragraphs(): void {
        $this->assertSame(
            "<p>Order 1012 from &lt;Ann &lt;b&gt;&gt; &amp; co<br />\nThanks</p>\n" . self::ITEMS . "\n",
            $this->render( "Order {{ order.number }} from <{{ customer.first_name }}> & co\nThanks\n\n{{ order.items }}" )
        );
    }

    public function test_plain_text_entities_are_not_encoded_twice(): void {
        // `wp_kses_post()` turns a lone `<` into `&lt;` when the text is saved again.
        $this->assertSame( "<p>Total &lt; 500</p>\n", $this->render( 'Total &lt; 500' ) );
    }

    public function test_html_inside_a_merge_tag_does_not_make_it_html(): void {
        // Filter arguments are values: escaped like any other.
        $this->assertSame( "<p>Hi &lt;i&gt;there&lt;/i&gt; &lt;3</p>\n", $this->render( 'Hi {{ customer.last_name | default: "<i>there</i>" }} <3' ) );
    }

    public function test_any_html_tag_makes_the_message_html(): void {
        $this->assertSame( "<p>Hi <b>you</b><br />\nbye</p>\n", $this->render( "Hi <b>you</b>\nbye" ) );
    }
}
