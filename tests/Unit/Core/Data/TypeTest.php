<?php
namespace YayMatik\Tests\Unit\Core\Data;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\Type;

/** Value types: text form and the type each condition type maps to. */
final class TypeTest extends TestCase {

    public function test_to_string(): void {
        $items = Type::list_of( Type::entity( 'order_item' ) );
        $this->assertTrue( $items->is( Type::LIST ) );
        $this->assertNull( $items->entity_type() );
        $this->assertSame( 'order_item', Type::entity( 'order_item' )->entity_type() );
        $this->assertSame( 'list<entity<order_item>>', $items->to_string() );
        $this->assertSame( 'list<list<string>>', Type::list_of( Type::list_of( Type::string() ) )->to_string() );
        $this->assertSame( 'money', Type::money()->to_string() );
    }

    public function test_from_condition_type(): void {
        foreach ( [ 'number', 'string', 'enum', 'date', 'boolean' ] as $type ) {
            $this->assertSame( $type, Type::from_condition_type( $type )->to_string() );
        }
        $this->assertSame( 'string', Type::from_condition_type( 'unknown' )->to_string() );
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
