<?php
namespace YayMatik\Tests\Unit\Core\Data;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\Type;

/** Value types: text form, parsing, and the condition type each maps to. */
final class TypeTest extends TestCase {

    /** @return array[] */
    public function texts(): array {
        return [
            [ 'string' ],
            [ 'number' ],
            [ 'boolean' ],
            [ 'date' ],
            [ 'datetime' ],
            [ 'money' ],
            [ 'enum' ],
            [ 'object' ],
            [ 'entity<order>' ],
            [ 'entity<order_item>' ],
            [ 'list<string>' ],
            [ 'list<entity<order_item>>' ],
            [ 'list<list<number>>' ],
        ];
    }

    /**
     * @dataProvider texts
     * @param string $text Type as text.
     */
    public function test_parse_and_to_string_round_trip( string $text ): void {
        $this->assertSame( $text, Type::parse( $text )->to_string() );
    }

    public function test_parse_rejects_unknown_types(): void {
        foreach ( [ '', 'float', 'entity<>', 'entity<Order>', 'list<>', 'list<float>', 'list<string', 'entity' ] as $text ) {
            $this->assertNull( Type::parse( $text ), $text );
        }
    }

    public function test_factories_and_parts(): void {
        $items = Type::list_of( Type::entity( 'order_item' ) );
        $this->assertTrue( $items->is( Type::LIST ) );
        $this->assertSame( 'order_item', $items->item()->entity_type() );
        $this->assertNull( $items->entity_type() );
        $this->assertNull( Type::money()->item() );
        $this->assertSame( 'list<entity<order_item>>', $items->to_string() );
    }

    public function test_legacy_maps_onto_condition_types(): void {
        $this->assertSame( DataItem::TYPE_NUMBER, Type::number()->legacy() );
        $this->assertSame( DataItem::TYPE_NUMBER, Type::money()->legacy() );
        $this->assertSame( DataItem::TYPE_DATE, Type::date()->legacy() );
        $this->assertSame( DataItem::TYPE_DATE, Type::datetime()->legacy() );
        $this->assertSame( DataItem::TYPE_ENUM, Type::enum()->legacy() );
        $this->assertSame( DataItem::TYPE_BOOLEAN, Type::boolean()->legacy() );
        $this->assertSame( DataItem::TYPE_STRING, Type::string()->legacy() );
        $this->assertSame( DataItem::TYPE_STRING, Type::object()->legacy() );
        $this->assertSame( DataItem::TYPE_STRING, Type::entity( 'order' )->legacy() );
        $this->assertSame( DataItem::TYPE_STRING, Type::list_of( Type::string() )->legacy() );
    }

    public function test_from_legacy(): void {
        foreach ( [ 'number', 'string', 'enum', 'date', 'boolean' ] as $legacy ) {
            $this->assertSame( $legacy, Type::from_legacy( $legacy )->to_string() );
        }
        $this->assertSame( 'string', Type::from_legacy( 'unknown' )->to_string() );
    }

    public function test_fields_default_their_full_type_from_the_condition_type(): void {
        $entity = new \YayMatik\Tests\Support\FakeEntity(
            'thing',
            [
                'count' => [
                    'label' => 'Count',
                    'type'  => DataItem::TYPE_NUMBER,
                    'value' => fn() => 1,
                ],
                'price' => [
                    'label'      => 'Price',
                    'type'       => DataItem::TYPE_NUMBER,
                    'value_type' => Type::money(),
                    'value'      => fn() => 1,
                ],
            ]
        );
        $this->assertSame( 'number', $entity->fields()['count']['value_type']->to_string() );
        $this->assertSame( 'money', $entity->fields()['price']['value_type']->to_string() );
    }
}
