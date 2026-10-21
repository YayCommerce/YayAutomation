<?php
namespace YayMatik\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Data\DataItem;
use YayMatik\Workflow\Data\Operators;

/** Condition operators per field type, with the new boolean ones. */
final class OperatorsTest extends TestCase {

    public function test_boolean_operators_take_no_value(): void {
        $this->assertSame( [ 'is_true', 'is_false' ], Operators::BY_TYPE[ DataItem::TYPE_BOOLEAN ] );
        $this->assertTrue( Operators::supports( DataItem::TYPE_BOOLEAN, 'is_true' ) );
        $this->assertFalse( Operators::supports( DataItem::TYPE_BOOLEAN, 'equal' ) );
        $this->assertFalse( Operators::supports( DataItem::TYPE_STRING, 'is_true' ) );
        $this->assertFalse( Operators::needs_value( 'is_true' ) );
        $this->assertFalse( Operators::needs_value( 'is_false' ) );
    }

    /** @return array[] */
    public function flags(): array {
        return [
            [ true, true ],
            [ false, false ],
            [ 1, true ],
            [ 0, false ],
            [ '1', true ],
            [ '0', false ],
            [ 'yes', true ],
            [ 'YES ', true ],
            [ 'no', false ],
            [ 'true', true ],
            [ '', false ],
            [ null, false ],
            [ [ 'yes' ], false ],
        ];
    }

    /**
     * @dataProvider flags
     * @param mixed $actual Field value.
     * @param bool  $truthy Whether it reads as true.
     */
    public function test_boolean_compare( $actual, bool $truthy ): void {
        $this->assertSame( $truthy, Operators::compare( DataItem::TYPE_BOOLEAN, 'is_true', $actual, null ) );
        $this->assertSame( ! $truthy, Operators::compare( DataItem::TYPE_BOOLEAN, 'is_false', $actual, null ) );
    }

    public function test_every_operator_has_one_label(): void {
        $ids = array_values( array_unique( array_merge( ...array_values( Operators::BY_TYPE ) ) ) );
        sort( $ids );
        $labels = array_keys( Operators::labels() );
        sort( $labels );
        $this->assertSame( $ids, $labels );
        $this->assertSame( 'is greater than', Operators::labels()['greater_than'] );
    }

    public function test_existing_types_unchanged(): void {
        $this->assertTrue( Operators::compare( DataItem::TYPE_NUMBER, 'greater_than', 10.5, '10' ) );
        $this->assertTrue( Operators::compare( DataItem::TYPE_STRING, 'contains', 'Hello', 'ELL' ) );
        $this->assertTrue( Operators::compare( DataItem::TYPE_ENUM, 'is', 'processing', 'processing' ) );
        $this->assertTrue( Operators::compare( DataItem::TYPE_DATE, 'before', '2026-10-08', '2026-10-09' ) );
        $this->assertTrue( Operators::needs_value( 'equal' ) );
        $this->assertFalse( Operators::needs_value( 'is_empty' ) );
    }
}
