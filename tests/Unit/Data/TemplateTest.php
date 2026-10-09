<?php
namespace YayAutomation\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use YayAutomation\Tests\Support\FakeValues;
use YayAutomation\Workflow\Data\Filters;
use YayAutomation\Workflow\Data\Template;
use YayAutomation\Workflow\Data\Variables;

/** Merge tags `{{ path | filter: arg }}`: old tags, paths, filters, escaping, malformed tags. */
final class TemplateTest extends TestCase {

    /** @var Template */
    private $template;

    protected function setUp(): void {
        $filters        = new Filters(
            fn( int $timestamp, string $format ) => gmdate( '' !== $format ? $format : 'Y-m-d', $timestamp ),
            fn( float $amount, string $currency ) => trim( ( '' !== $currency ? $currency : 'USD' ) . ' ' . number_format( $amount, 2 ) ),
            new \DateTimeZone( 'UTC' )
        );
        $this->template = new Template( $filters );
    }

    public function test_old_tags_render_their_display_text(): void {
        $values = new FakeValues(
            [ 'order.number' => '12' ],
            [
                'order.total' => [
                    'text' => '$42.00',
                    'html' => '<span class="amount">$42.00</span>',
                ],
            ]
        );
        $this->assertSame( 'Order 12: $42.00', $this->template->render( 'Order {{ order.number }}: {{order.total}}', $values ) );
        // Display text is already safe HTML: not escaped again. The text around it is.
        $this->assertSame(
            'A &amp; B <span class="amount">$42.00</span>',
            $this->template->render( 'A & B {{ order.total }}', $values, true )
        );
    }

    public function test_literal_html_is_escaped_but_display_html_is_not(): void {
        $values = new FakeValues(
            [],
            [
                'order.items' => [
                    'text' => 'Mug x 1',
                    'html' => '<table><tr><td>Mug</td></tr></table>',
                ],
            ]
        );
        // `<b>` typed in the template is text: escaped. The field's own display HTML stays as it is.
        $this->assertSame(
            '&lt;b&gt;Items&lt;/b&gt; <table><tr><td>Mug</td></tr></table>',
            $this->template->render( '<b>Items</b> {{ order.items }}', $values, true )
        );
        $this->assertSame( '<b>Items</b> Mug x 1', $this->template->render( '<b>Items</b> {{ order.items }}', $values ) );
    }

    public function test_path_segments_after_the_first_take_any_step_id(): void {
        $values = new FakeValues(
            [
                'steps.Email1.code' => 'A',
                'steps.1abc.code'   => 'B',
            ]
        );
        $this->assertSame( 'A B', $this->template->render( '{{ steps.Email1.code }} {{ steps.1abc.code }}', $values ) );
        $this->assertSame( [ 'steps.Email1.code', 'steps.1abc.code' ], $this->template->paths( '{{ steps.Email1.code }} {{ steps.1abc.code }}' ) );
        // The first segment is a data item: lowercase only.
        $this->assertSame( [], $this->template->paths( '{{ Steps.Email1.code }} {{ 1steps.a }}' ) );
    }

    public function test_paths_match_the_old_merge_tag_keys(): void {
        $text = 'Order {{ order.number }} for {{customer.email}} ({{ order.number }}), {{ order }} {{ Order.Total }}';
        $this->assertSame( Variables::keys( $text ), $this->template->paths( $text ) );
    }

    public function test_deep_paths_and_step_ids(): void {
        $values = new FakeValues(
            [
                'order.customer.first_name' => 'Ana',
                'steps.action-k2x9.code'    => 'YAY-7K2P',
            ]
        );
        $this->assertSame(
            'Hi Ana, use YAY-7K2P',
            $this->template->render( 'Hi {{ order.customer.first_name }}, use {{ steps.action-k2x9.code }}', $values )
        );
        $this->assertSame( [ 'order.customer.first_name', 'steps.action-k2x9.code' ], $this->template->paths( '{{ order.customer.first_name }} {{ steps.action-k2x9.code }}' ) );
    }

    public function test_missing_value_prints_empty(): void {
        $this->assertSame( 'Hi !', $this->template->render( 'Hi {{ customer.first_name }}!', new FakeValues( [] ) ) );
        $this->assertSame( 'Hi !', $this->template->render( 'Hi {{ customer.first_name | upper }}!', new FakeValues( [] ) ) );
    }

    public function test_default_filter(): void {
        $template = '{{ customer.first_name | default: "there" }}';
        $this->assertSame( 'there', $this->template->render( $template, new FakeValues( [] ) ) );
        $this->assertSame( 'there', $this->template->render( $template, new FakeValues( [ 'customer.first_name' => '  ' ] ) ) );
        $this->assertSame( 'Ana', $this->template->render( $template, new FakeValues( [ 'customer.first_name' => 'Ana' ] ) ) );
    }

    public function test_filters_chain_and_take_args(): void {
        $values = new FakeValues(
            [
                'customer.first_name' => 'ana',
                'order.created'       => '2026-10-09 15:30:00',
                'order.total'         => 42,
                'coupon.value'        => [
                    'amount'   => 10,
                    'currency' => 'EUR',
                ],
                'order.skus'          => [ 'A1', '', 'B2' ],
            ]
        );
        $this->assertSame( 'ANA', $this->template->render( '{{ customer.first_name | upper }}', $values ) );
        $this->assertSame( 'ana', $this->template->render( '{{ customer.first_name | upper | lower }}', $values ) );
        $this->assertSame( 'Oct 9', $this->template->render( '{{ order.created | date: "M j" }}', $values ) );
        $this->assertSame( '2026-10-09', $this->template->render( '{{ order.created | date }}', $values ) );
        $this->assertSame( 'USD 42.00', $this->template->render( '{{ order.total | money }}', $values ) );
        $this->assertSame( 'EUR 10.00', $this->template->render( '{{ coupon.value | money }}', $values ) );
        $this->assertSame( 'A1 / B2', $this->template->render( "{{ order.skus | join: ' / ' }}", $values ) );
        $display = new FakeValues(
            [],
            [
                'order.skus' => [
                    'text' => 'A1, B2',
                    'html' => 'A1, B2',
                ],
            ]
        );
        $this->assertSame( 'A1, B2', $this->template->render( '{{ order.skus }}', $display ) );
    }

    public function test_filter_output_is_escaped_in_html(): void {
        $values = new FakeValues( [ 'customer.first_name' => '<b>ana</b>' ] );
        $this->assertSame( '&lt;B&gt;ANA&lt;/B&gt;', $this->template->render( '{{ customer.first_name | upper }}', $values, true ) );
    }

    public function test_filters_never_fail_on_input_they_cannot_use(): void {
        $values = new FakeValues( [ 'order.total' => 42 ] );
        $this->assertSame( '42', $this->template->render( '{{ order.total | upper }}', $values ) );
        $this->assertSame( '42', $this->template->render( '{{ order.total | nope }}', $values ) );
        $this->assertSame( 'soon', $this->template->render( '{{ order.note | date }}', new FakeValues( [ 'order.note' => 'soon' ] ) ) );
    }

    public function test_malformed_tags_stay_as_written_and_are_reported(): void {
        $values = new FakeValues( [ 'order.number' => '12' ] );
        $this->assertSame( 'x {{ order. }} y', $this->template->render( 'x {{ order. }} y', $values ) );
        $this->assertSame( '{{ order.number | default: "open }}', $this->template->render( '{{ order.number | default: "open }}', $values ) );
        $this->assertSame( '{{ order.number | 2up }}', $this->template->render( '{{ order.number | 2up }}', $values ) );

        $errors = $this->template->errors( '{{ order. }} {{ order.number | shout }} {{ order.number | default: oops }} {{ order.number }}' );
        $this->assertCount( 3, $errors );
        $this->assertStringContainsString( '{{ order. }}', $errors[0] );
        $this->assertStringContainsString( 'shout', $errors[1] );
        $this->assertStringContainsString( 'oops', $errors[2] );
        $this->assertSame( [], $this->template->errors( 'Hi {{ customer.first_name | default: "there" }}' ) );
    }

    public function test_parse_keeps_text_and_tags_in_order(): void {
        $tokens = $this->template->parse( 'Hi {{ customer.first_name | default: "there", 2 }}!' );
        $this->assertSame( [ 'text', 'tag', 'text' ], array_column( $tokens, 'type' ) );
        $this->assertSame( 'customer.first_name', $tokens[1]['path'] );
        $this->assertSame(
            [
                [
                    'name' => 'default',
                    'args' => [ 'there', 2 ],
                ],
            ],
            $tokens[1]['filters']
        );
    }

    public function test_escaped_quotes_and_separators_inside_args(): void {
        $values = new FakeValues( [ 'order.skus' => [ 'A', 'B' ] ] );
        $this->assertSame( 'A | B', $this->template->render( '{{ order.skus | join: " | " }}', $values ) );
        $this->assertSame( 'A,"B', $this->template->render( '{{ order.skus | join: ",\"" }}', $values ) );
    }
}
