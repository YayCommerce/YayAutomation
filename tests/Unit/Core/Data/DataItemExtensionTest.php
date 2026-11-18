<?php
namespace YayMatik\Tests\Unit\Core\Data;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\DataItems\OrderData;
use YayMatik\Workflow\DataItems\SiteData;

/** Dynamic field extensions stay scoped to their data item and compose. */
final class DataItemExtensionTest extends TestCase {

    /** @var callable[] */
    private $filters = [];

    protected function tearDown(): void {
        foreach ( $this->filters as $filter ) {
            remove_filter( 'yaymatik_data_item_site_fields', $filter );
        }
        $this->filters = [];
    }

    public function test_plugins_can_append_fields_to_one_data_item(): void {
        $this->filters[] = function ( array $fields, DataItem $item ): array {
            $this->assertSame( SiteData::ID, $item->id() );
            $fields += [
                'first_plugin_field' => [
                    'label' => 'First plugin field',
                    'type'  => DataItem::TYPE_STRING,
                    'value' => static fn() => 'first',
                ],
            ];
            return $fields;
        };
        $this->filters[] = function ( array $fields ): array {
            $fields += [
                'second_plugin_field' => [
                    'label'       => 'Second plugin field',
                    'type'        => DataItem::TYPE_BOOLEAN,
                    'value'       => static fn() => true,
                    'conditional' => false,
                ],
            ];
            return $fields;
        };
        foreach ( $this->filters as $filter ) {
            add_filter( 'yaymatik_data_item_site_fields', $filter );
        }

        $fields = ( new SiteData() )->fields();

        $this->assertSame( [ 'name', 'url', 'account_url', 'shop_url', 'welcome_links', 'shop_button', 'account_button', 'new_products', 'first_plugin_field', 'second_plugin_field' ], array_keys( $fields ) );
        $this->assertTrue( $fields['first_plugin_field']['conditional'], 'core applies field defaults after integrations' );
        $this->assertFalse( $fields['second_plugin_field']['conditional'] );
        $this->assertArrayNotHasKey( 'first_plugin_field', ( new OrderData() )->fields(), 'the dynamic hook is scoped to Site' );
    }

    public function test_a_field_is_a_merge_tag_by_default(): void {
        $fields = ( new OrderData() )->fields();
        $this->assertTrue( $fields['total']['merge_tag'] );
    }
}
