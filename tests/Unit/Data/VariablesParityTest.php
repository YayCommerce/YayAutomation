<?php
namespace YayAutomation\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use YayAutomation\Tests\Support\ContextScope;
use YayAutomation\Tests\Support\LegacyVariables;
use YayAutomation\Workflow\Data\DataRegistry;
use YayAutomation\Workflow\Data\Template;
use YayAutomation\Workflow\Data\Variables;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** Template + Scope render every old `{{ item.field }}` tag byte for byte as v0.1 did (`LegacyVariables`). */
final class VariablesParityTest extends TestCase {

    /**
     * What steps render today: the one Template over a Scope with the context's entities as trigger refs.
     *
     * @param string $text    Text with merge tags.
     * @param array  $context v0.1 run context.
     * @param bool   $html    Safe HTML.
     */
    private static function render( string $text, array $context, bool $html = false ): string {
        return ( new Template() )->render( $text, ContextScope::of( $context ), $html );
    }

    private function order( array $data = [] ): \WC_Order {
        return new \WC_Order(
            $data + [
                'id'                   => 34,
                'order_number'         => '1034',
                'total'                => '1234.5',
                'subtotal'             => '1200',
                'currency'             => 'USD',
                'status'               => 'processing',
                'payment_method'       => 'cod',
                'payment_method_title' => 'Cash <on> delivery',
                'shipping_method'      => 'Flat & rate',
                'item_count'           => 3,
                'date_created'         => new \WC_DateTime( '2026-10-09 10:00:00' ),
                'edit_order_url'       => 'https://shop.test/wp-admin/post.php?post=34&action=edit',
                'billing_email'        => 'ann@example.test',
                'billing_first_name'   => 'Ann "A"',
                'billing_last_name'    => "O'Neil",
                'billing_phone'        => '+1 555',
                'billing_country'      => 'US',
            ]
        );
    }

    /** Every old tag, in the spellings the old pattern accepted. */
    private function old_tags(): string {
        $tags = [];
        foreach ( DataRegistry::items() as $item ) {
            foreach ( array_keys( $item->fields() ) as $key ) {
                $tags[] = '{{ ' . $item->id() . '.' . $key . ' }}';
            }
        }
        return implode( "\n", $tags );
    }

    /** @return array[] */
    public function texts(): array {
        return [
            'empty'             => [ '' ],
            'no tags'           => [ "Plain <b>text</b> & 'quotes' \"here\"\nline 2" ],
            'spacing'           => [ '{{order.number}} {{ order.number }} {{   order.number   }} {{ order.number' . "\n" . '}}' ],
            'adjacent'          => [ '{{order.number}}{{order.total}}{{customer.email}}' ],
            'edges'             => [ '{{ order.number }} middle {{ customer.first_name }}' ],
            'unknown'           => [ '{{ order.nope }} {{ nope.field }} {{ customer.nope }}' ],
            'not tags'          => [ '{{ order }} {{ Order.Number }} {{ order.number | }} {{ 1.2 }} {{}} {{ }} }} {{' ],
            'stray braces'      => [ 'a {{ b } c {{ order.number }} d } {{ e' ],
            'triple braces'     => [ '{{{ order.number }}} {{{{ order.total }}}}' ],
            'html around'       => [ '<p>{{ order.number }}</p> &amp; &lt; < > " \' {{ order.items }}' ],
            'relation is blank' => [ '{{ order.customer }}' ],
        ];
    }

    /**
     * @dataProvider texts
     * @param string $text Template text.
     */
    public function test_renders_like_the_old_pattern( string $text ): void {
        $contexts = [
            'order and customer' => [
                'order'    => $this->order(),
                'customer' => $this->order(),
            ],
            'order only'         => [ 'order' => $this->order() ],
            'status change'      => [
                'order'    => $this->order(),
                'customer' => $this->order(),
                'from'     => 'pending',
                'to'       => 'processing',
            ],
            'nothing'            => [],
        ];
        foreach ( $contexts as $name => $context ) {
            foreach ( [ false, true ] as $html ) {
                $this->assertSame(
                    LegacyVariables::render( $text, $context, $html ),
                    self::render( $text, $context, $html ),
                    $name . ( $html ? ' / html' : ' / plain' )
                );
            }
        }
    }

    public function test_every_old_tag_renders_the_same(): void {
        $text    = $this->old_tags();
        $context = [
            'order'    => $this->order(),
            'customer' => $this->order(),
        ];
        foreach ( [ false, true ] as $html ) {
            $this->assertSame( LegacyVariables::render( $text, $context, $html ), self::render( $text, $context, $html ) );
        }
        // A field with its own display text prints it even when its value is empty.
        $blank = [
            'order' => $this->order(
                [
                    'date_created'   => null,
                    'payment_method' => null,
                ]
            ),
        ];
        $text  = '{{ order.created_date }}|{{ order.payment_method }}';
        $this->assertSame( '|Cash <on> delivery', LegacyVariables::render( $text, $blank ) );
        $this->assertSame( LegacyVariables::render( $text, $blank ), self::render( $text, $blank ) );
    }

    public function test_keys_match_for_old_tags(): void {
        $text = $this->old_tags() . ' {{order.number}} {{ order.nope }} {{ nope.field }}';
        $this->assertSame( LegacyVariables::keys( $text ), Variables::keys( $text ) );
    }
}
