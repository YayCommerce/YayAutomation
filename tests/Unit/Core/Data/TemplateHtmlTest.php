<?php
namespace YayMatik\Tests\Unit\Core\Data;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeValues;
use YayMatik\Workflow\Core\Data\Template;

/** `Template::render_html()`: stored HTML kept, each value escaped for where it lands. */
final class TemplateHtmlTest extends TestCase {

    private const ITEMS = '<table class="td"><tr><td>Mug &amp; spoon</td></tr></table>';

    private function render( string $html, array $values = [], array $texts = [] ): string {
        $values += [
            'customer.first_name' => 'Ann <b>"Bo"</b> & \'Cy\'',
            'site.url'            => 'https://shop.test/',
            'evil.url'            => 'javascript:alert(1)',
            'product.image'       => 'https://shop.test/a b.png',
            'coupon.code'         => 'YM&X',
        ];
        $texts  += [
            'order.items' => [
                'text' => 'Mug & spoon',
                'html' => self::ITEMS,
            ],
        ];
        return ( new Template() )->render_html( $html, new FakeValues( $values, $texts ) );
    }

    public function test_text_values_are_escaped_and_the_html_kept(): void {
        $this->assertSame(
            '<p>Hi <strong>Ann &lt;b&gt;&quot;Bo&quot;&lt;/b&gt; &amp; &#039;Cy&#039;</strong>!</p>',
            $this->render( '<p>Hi <strong>{{ customer.first_name }}</strong>!</p>' )
        );
    }

    public function test_href_is_escaped_as_a_url_once_filled_in(): void {
        $this->assertSame( '<a href="https://shop.test/">Shop</a>', $this->render( '<a href="{{ site.url }}">Shop</a>' ) );
        $this->assertSame( '<a href="https://shop.test/?c=YM%26X">Use</a>', $this->render( '<a href="{{ site.url }}?c={{ coupon.code }}">Use</a>' ) );
        $this->assertSame( '<a href="">x</a>', $this->render( '<a href="{{ evil.url }}">x</a>' ), 'a bad protocol is dropped' );
        $this->assertSame( "<a href='https://shop.test/'>x</a>", $this->render( "<a href='{{ site.url }}'>x</a>" ) );
    }

    public function test_a_value_further_in_a_url_is_encoded(): void {
        $this->assertSame(
            '<a href="https://shop.test/?email=ann%2Bshop%40x.com&#038;n=Ann%20B">x</a>',
            $this->render(
                '<a href="https://shop.test/?email={{ customer.email }}&amp;n={{ customer.last_name }}">x</a>',
                [
                    'customer.email'     => 'ann+shop@x.com',
                    'customer.last_name' => 'Ann B',
                ]
            )
        );
        $this->assertSame( '<img src="https://shop.test/i/a%2Fb.png">', $this->render( '<img src="{{ site.url }}i/{{ customer.last_name }}.png">', [ 'customer.last_name' => 'a/b' ] ) );
        $this->assertSame( '<a href="mailto:ann%2Bshop%40x.com">x</a>', $this->render( '<a href="mailto:{{ customer.email }}">x</a>', [ 'customer.email' => 'ann+shop@x.com' ] ) );
    }

    public function test_a_url_attribute_without_values_stays_as_written(): void {
        $html = '<a href="https://shop.test/?a=1&amp;b=2" title="T">x</a>';
        $this->assertSame( $html, $this->render( $html ) );
    }

    public function test_src_is_a_url_and_alt_an_attribute(): void {
        $this->assertSame(
            '<img src="https://shop.test/a%20b.png" alt="Ann &lt;b&gt;&quot;Bo&quot;&lt;/b&gt; &amp; &#039;Cy&#039;">',
            $this->render( '<img src="{{ product.image }}" alt="{{ customer.first_name }}">' )
        );
    }

    public function test_other_attributes_are_escaped_as_attributes(): void {
        $this->assertSame(
            '<span title="Hi Ann &lt;b&gt;&quot;Bo&quot;&lt;/b&gt; &amp; &#039;Cy&#039;" data-x="1">a</span>',
            $this->render( '<span title="Hi {{ customer.first_name }}" data-x="1">a</span>' )
        );
    }

    public function test_an_unquoted_value_cannot_break_out(): void {
        $this->assertSame( '<span title=Ann&#32;&lt;b&gt;&quot;Bo&quot;&lt;/b&gt;&#32;&amp;&#32;&#039;Cy&#039;>a</span>', $this->render( '<span title={{ customer.first_name }}>a</span>' ) );
    }

    public function test_a_value_inside_a_tag_outside_attributes_is_dropped(): void {
        $this->assertSame( '<a  href="#">x</a>', $this->render( '<a {{ customer.first_name }} href="#">x</a>' ) );
    }

    public function test_a_block_alone_in_a_paragraph_replaces_it(): void {
        $this->assertSame( '<p>Your order:</p>' . self::ITEMS, $this->render( '<p>Your order:</p><p>{{ order.items }}</p>' ) );
        $this->assertSame( self::ITEMS, $this->render( '<p style="text-align: center;"> {{ order.items }} </p>' ) );
    }

    public function test_a_block_with_text_around_it_keeps_its_paragraph(): void {
        $this->assertSame( '<p>Items: ' . self::ITEMS . '</p>', $this->render( '<p>Items: {{ order.items }}</p>' ) );
    }

    public function test_a_block_in_an_attribute_is_its_plain_text(): void {
        $this->assertSame( '<span title="Mug &amp; spoon">a</span>', $this->render( '<span title="{{ order.items }}">a</span>' ) );
    }

    public function test_a_value_with_tags_in_it_is_not_rendered_again(): void {
        $this->assertSame( '<p>{{ site.url }}</p>', $this->render( '<p>{{ customer.first_name }}</p>', [ 'customer.first_name' => '{{ site.url }}' ] ) );
    }

    public function test_markers_in_values_change_nothing(): void {
        $this->assertSame( '<p>ab</p>', $this->render( '<p>{{ customer.first_name }}</p>', [ 'customer.first_name' => "a\u{E000}b\u{E001}" ] ) );
    }

    public function test_filters_and_malformed_tags(): void {
        $this->assertSame( '<p>there</p>', $this->render( '<p>{{ customer.last_name | default: "there" }}</p>' ) );
        $this->assertSame( '<p>{{ order. }}</p>', $this->render( '<p>{{ order. }}</p>' ) );
        $this->assertSame( '<a href="http://shop.test">x</a>', $this->render( '<a href="{{ customer.last_name | default: "shop.test" }}">x</a>' ) );
    }

    public function test_comments_escape_values_as_text(): void {
        $this->assertSame( '<!-- Ann &lt;b&gt;&quot;Bo&quot;&lt;/b&gt; &amp; &#039;Cy&#039; --><p>a</p>', $this->render( '<!-- {{ customer.first_name }} --><p>a</p>' ) );
    }
}
