<?php
namespace YayMatik\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use YayMatik\Api\CustomerView;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Extension\Diagnostics;
use YayMatik\Workflow\Extension\FieldAdapter;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** Public data field arrays as core field definitions: views, kses, guards, relations. */
final class FieldAdapterTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_options'] = [];
    }

    public function test_a_customer_field_gets_a_customer_view_whatever_the_source(): void {
        $seen  = [];
        $field = FieldAdapter::field(
            'acme',
            'customer',
            'acme_points',
            [
                'label' => 'Points',
                'type'  => 'number',
                'value' => function ( $customer ) use ( &$seen ) {
                    $seen[] = $customer instanceof CustomerView ? [ $customer->email(), $customer->user_id(), $customer->order_id() ] : null;
                    return 10;
                },
            ]
        );

        ( $field['value'] )(
            new \WC_Order(
                [
                    'id'            => 12,
                    'customer_id'   => 7,
                    'billing_email' => 'ann@example.test',
                ]
            )
        );
        ( $field['value'] )(
            new \WC_Customer(
                [
                    'id'            => 5,
                    'billing_email' => 'bo@example.test',
                ]
            )
        );

        $this->assertSame( [ [ 'ann@example.test', 7, 12 ], [ 'bo@example.test', 5, 0 ] ], $seen );
        $this->assertSame( [ DataItem::TYPE_NUMBER, 'number', true, true ], [ $field['type'], $field['value_type']->to_string(), $field['conditional'], $field['merge_tag'] ] );
    }

    public function test_display_html_is_kses_d_and_a_throw_reads_empty_with_a_diagnostic(): void {
        $field = FieldAdapter::field(
            'acme',
            'order',
            'acme_badge',
            [
                'label'   => 'Badge',
                'type'    => 'string',
                'value'   => function () {
                    throw new \RuntimeException( 'Badge service down.' );
                },
                'display' => fn( $order, bool $html ) => $html ? '<b>Gold</b><script>x()</script>' : 'Gold',
                'block'   => true,
            ]
        );

        $this->assertSame( '<b>Gold</b>', ( $field['display'] )( null, true ) );
        $this->assertSame( 'Gold', ( $field['display'] )( null, false ) );
        $this->assertTrue( $field['block'] );
        $this->assertNull( ( $field['value'] )( null ) );
        $entry = Diagnostics::recent( 24 )[0];
        $this->assertSame( [ 'acme', 'field order.acme_badge', 'Badge service down.' ], [ $entry['module'], $entry['callback'], $entry['message'] ] );
    }

    public function test_an_entity_field_is_a_relation_not_a_condition(): void {
        $field = FieldAdapter::field(
            'acme',
            'customer',
            'acme_last_order',
            [
                'label' => 'Last rewarded order',
                'type'  => 'entity',
                'item'  => 'order',
                'value' => fn() => 12,
            ]
        );
        $list  = FieldAdapter::field(
            'acme',
            'customer',
            'acme_orders',
            [
                'label' => 'Rewarded orders',
                'type'  => 'list',
                'of'    => 'entity',
                'value' => fn() => [ 3, 4 ],
            ]
        );

        $this->assertEquals( new Ref( 'order', '12' ), ( $field['value'] )( null ) );
        $this->assertFalse( $field['conditional'] );
        $this->assertSame( 'Field "acme_orders" needs a "label" and a known "type".', $list, 'a list of entities names no item' );
    }

    public function test_a_customer_relation_points_at_the_account_and_a_sample_is_kept(): void {
        $field = FieldAdapter::field(
            'acme2',
            'order',
            'acme2_referrer',
            [
                'label'  => 'Referrer',
                'type'   => 'entity',
                'item'   => 'customer',
                'value'  => fn() => 5,
                'sample' => 'Ann',
            ]
        );

        $this->assertEquals( new Ref( 'customer', 'user:5' ), ( $field['value'] )( null ) );
        $this->assertSame( 'Ann', $field['sample'] );
    }

    public function test_enum_options_from_a_callable_or_an_array(): void {
        $callable = FieldAdapter::field(
            'acme',
            'customer',
            'acme_tier',
            [
                'label'   => 'Tier',
                'type'    => 'enum',
                'value'   => fn() => 'gold',
                'options' => fn() => [ 'gold' => 'Gold' ],
            ]
        );
        $none     = FieldAdapter::field(
            'acme',
            'customer',
            'acme_tag',
            [
                'label' => 'Tag',
                'type'  => 'enum',
                'value' => fn() => 'x',
            ]
        );

        $this->assertSame( 'Gold', DataItem::display( $callable + [ 'display' => null ], null, false ) );
        $this->assertSame( [], ( $none['options'] )() );
    }

    public function test_refused_shapes(): void {
        $this->assertSame( 'Field key "Acme_x" must be lowercase letters, digits and underscores, starting with a letter.', FieldAdapter::field( 'acme', 'order', 'Acme_x', [] ) );
        $this->assertSame(
            'Field "acme_x" needs a callable "value".',
            FieldAdapter::field(
                'acme',
                'order',
                'acme_x',
                [
                    'label' => 'X',
                    'type'  => 'string',
                ]
            )
        );
        $this->assertSame(
            'Fact "points" needs a "label" and a known "type".',
            FieldAdapter::fact(
                'acme',
                'points',
                [
                    'label' => 'Points',
                    'type'  => 'list',
                ]
            )
        );
    }
}
