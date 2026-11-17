<?php
namespace YayMatik\Tests\Unit\Core\Definition;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Definition\Catalog;
use YayMatik\Workflow\Core\Steps\StepIcons;
use YayMatik\Workflow\Core\Steps\StepRegistry;

require_once dirname( __DIR__, 3 ) . '/Support/woocommerce-stubs.php';

/** The step catalog sent to the admin app: JSON-safe, camelCase. */
final class CatalogTest extends TestCase {

    private function steps(): array {
        return Catalog::steps( StepRegistry::all() );
    }

    public function test_each_step_keeps_its_v01_keys_first_then_the_contract(): void {
        $steps = $this->steps();

        $this->assertSame( [ 'kind', 'plugin', 'provides', 'category', 'label' ], array_slice( array_keys( $steps['order_created'] ), 0, 5 ) );
        $this->assertSame( [ 'kind', 'plugin', 'category', 'requires', 'label' ], array_slice( array_keys( $steps['add_order_note'] ), 0, 5 ) );
        foreach ( $steps as $id => $step ) {
            foreach ( [ 'kind', 'plugin', 'label', 'description', 'icon', 'summary', 'onError', 'fields', 'outputs' ] as $key ) {
                $this->assertArrayHasKey( $key, $step, "$id.$key" );
            }
        }
        $this->assertSame( 'yaymatik', $steps['condition']['plugin'] );
    }

    public function test_plugins_and_step_identity_match_the_app_fixture(): void {
        $fixture = json_decode( (string) file_get_contents( dirname( __DIR__, 4 ) . '/apps/admin-page/src/test/fixtures/' . ( YAYMATIK_TEST_LITE ? 'meta-lite.json' : 'meta.json' ) ), true )['automation']; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a fixture in a test.
        $catalog = Catalog::to_client();

        $without_active = fn( array $plugins ) => array_map( fn( array $plugin ) => array_diff_key( $plugin, [ 'active' => true ] ), $plugins );
        $this->assertSame( $without_active( $fixture['plugins'] ), $without_active( $catalog['plugins'] ) );
        $identity       = fn( array $steps ) => array_map(
            fn( array $step ) => [
                'kind'   => $step['kind'],
                'plugin' => $step['plugin'],
                'keys'   => array_keys( $step ),
            ],
            $steps
        );
        $this->assertSame( $identity( $fixture['steps'] ), $identity( $catalog['steps'] ) );
    }

    public function test_it_is_plain_json_in_camel_case(): void {
        $steps = $this->steps();
        $this->assertIsString( wp_json_encode( $steps ), 'no closures or objects' );

        $keys = [];
        foreach ( $steps as $step ) {
            $keys = array_merge( $keys, array_keys( $step ) );
            foreach ( array_merge( $step['fields'], $step['outputs'] ) as $entry ) {
                $keys = array_merge( $keys, array_keys( $entry ), array_keys( $entry['showIf'] ?? [] ) );
                foreach ( $entry['options'] ?? [] as $option ) {
                    $keys = array_merge( $keys, array_keys( $option ) );
                }
            }
        }
        foreach ( array_unique( $keys ) as $key ) {
            $this->assertMatchesRegularExpression( '/^[a-z]+([A-Z][a-z]+)*$/', $key );
        }
    }

    public function test_fields_carry_widgets_and_options_as_value_label_lists(): void {
        $note = $this->steps()['add_order_note']['fields'];

        $this->assertSame( [ 'type', 'note' ], array_column( $note, 'key' ) );
        $this->assertSame(
            [
                [
                    'value' => 'private',
                    'label' => 'Private note',
                ],
                [
                    'value' => 'customer',
                    'label' => 'Note to customer',
                ],
            ],
            $note[0]['options']
        );
        $this->assertTrue( $note[1]['required'] );
        $this->assertSame( '{{ step }} needs a note.', $note[1]['requiredMessage'] );
        $this->assertTrue( $note[1]['variables'] );
        $this->assertSame( 'notice', $this->steps()['send_internal_email']['fields'][0]['type'] );
        $this->assertSame( 'pending', $this->steps()['change_order_status']['fields'][0]['options'][0]['value'], 'statuses read when the catalog is built' );
    }

    public function test_outputs_are_a_list_with_type_strings(): void {
        $steps = $this->steps();

        $this->assertSame(
            [
                [
                    'key'     => 'result',
                    'label'   => 'Result',
                    'type'    => 'enum',
                    'options' => [
                        [
                            'value' => 'yes',
                            'label' => 'Yes',
                        ],
                        [
                            'value' => 'no',
                            'label' => 'No',
                        ],
                    ],
                ],
            ],
            $steps['condition']['outputs']
        );
        $this->assertSame( 'list<string>', $steps['send_internal_email']['outputs'][0]['type'] );
        $this->assertSame( [ 'from', 'to' ], array_column( $steps['change_order_status']['outputs'], 'key' ) );
        $this->assertSame(
            [
                'key'   => 'box',
                'label' => 'Coupon box',
                'type'  => 'string',
                'block' => true,
            ],
            $steps['generate_coupon']['outputs'][3],
            'a block says so; its display callback stays on the server'
        );
        $this->assertArrayNotHasKey( 'block', $steps['generate_coupon']['outputs'][0] );
    }

    public function test_data_items_list_relations_apart_from_fields(): void {
        $order = array_column( DataRegistry::to_client(), null, 'id' )['order'];

        $this->assertSame(
            [
                [
                    'key'   => 'order.customer',
                    'label' => 'Customer',
                    'item'  => 'customer',
                ],
            ],
            $order['relations'],
            'one entity: a relation; a list (`order.items`) stays a field'
        );
        $this->assertNotContains( 'order.customer', array_column( $order['fields'], 'key' ) );
        $this->assertContains( 'order.items', array_column( $order['fields'], 'key' ) );
    }

    public function test_only_block_data_fields_say_so_and_they_are_not_conditional(): void {
        $fields = [];
        foreach ( DataRegistry::to_client() as $item ) {
            $fields += array_column( $item['fields'], null, 'key' );
        }
        $blocks = array_keys( array_filter( $fields, fn( array $field ) => ! empty( $field['block'] ) ) );

        $this->assertSame( [ 'site.welcome_links', 'site.shop_button', 'site.account_button', 'site.new_products', 'order.cross_sell_products', 'order.upsell_products', 'order.review_products', 'cart.items', 'cart.restore_button' ], $blocks );
        $this->assertFalse( $fields['order.cross_sell_products']['conditional'] );
        $this->assertSame( 'boolean', $fields['order.has_cross_sells']['type'] );
        $this->assertTrue( $fields['order.has_cross_sells']['conditional'] );
        $this->assertSame( 'number', $fields['customer.paid_order_count']['type'] );
    }

    public function test_search_lists_send_their_kind_and_no_values(): void {
        $fields = [];
        foreach ( DataRegistry::to_client() as $item ) {
            $fields += array_column( $item['fields'], null, 'key' );
        }
        foreach ( [
            'order.product_ids'              => 'product',
            'order.category_ids'             => 'category',
            'order.coupon_codes'             => 'coupon',
            'customer.purchased_product_ids' => 'product',
        ] as $key => $search ) {
            $this->assertSame( $search, $fields[ $key ]['search'], $key );
            $this->assertArrayNotHasKey( 'options', $fields[ $key ], $key );
        }
        $this->assertArrayNotHasKey( 'search', $fields['order.total'] );
    }

    public function test_data_items_name_a_step_icon(): void {
        foreach ( DataRegistry::to_client() as $item ) {
            $this->assertContains( $item['icon'], StepIcons::NAMES, $item['id'] );
        }
    }
}
