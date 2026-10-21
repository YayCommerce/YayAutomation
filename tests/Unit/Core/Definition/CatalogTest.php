<?php
namespace YayMatik\Tests\Unit\Core\Definition;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Definition\Catalog;
use YayMatik\Workflow\Core\Steps\StepIcons;
use YayMatik\Workflow\Core\Steps\StepRegistry;

require_once dirname( __DIR__, 3 ) . '/Support/woocommerce-stubs.php';

/** The step catalog sent to the admin app: v0.1 keys kept, contract keys added, JSON-safe, camelCase. */
final class CatalogTest extends TestCase {

    private function steps(): array {
        return Catalog::steps( StepRegistry::all() );
    }

    public function test_each_step_keeps_its_v01_keys_first_then_the_contract(): void {
        $steps = $this->steps();

        $this->assertSame( [ 'kind', 'plugin', 'provides', 'label' ], array_slice( array_keys( $steps['order_created'] ), 0, 4 ) );
        $this->assertSame( [ 'kind', 'plugin', 'category', 'requires', 'label' ], array_slice( array_keys( $steps['add_order_note'] ), 0, 5 ) );
        foreach ( $steps as $id => $step ) {
            foreach ( [ 'kind', 'plugin', 'label', 'description', 'icon', 'summary', 'onError', 'fields', 'outputs' ] as $key ) {
                $this->assertArrayHasKey( $key, $step, "$id.$key" );
            }
        }
        $this->assertSame( 'yaymatik', $steps['condition']['plugin'] );
    }

    public function test_plugins_and_step_identity_match_the_app_fixture(): void {
        $fixture = json_decode( (string) file_get_contents( dirname( __DIR__, 4 ) . '/apps/admin-page/src/test/fixtures/meta.json' ), true )['automation']; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a fixture in a test.
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
        $this->assertSame( '%s needs a note.', $note[1]['requiredMessage'] );
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
        foreach ( $steps as $step ) {
            foreach ( $step['outputs'] as $output ) {
                $this->assertNotNull( Type::parse( $output['type'] ), $output['type'] );
            }
        }
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

    public function test_data_items_name_a_step_icon(): void {
        foreach ( DataRegistry::to_client() as $item ) {
            $this->assertContains( $item['icon'], StepIcons::NAMES, $item['id'] );
        }
    }
}
