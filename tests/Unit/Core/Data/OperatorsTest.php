<?php
namespace YayMatik\Tests\Unit\Core\Data;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\Operators;

/** Condition operators per field type. */
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

    public function test_lists_ignore_case_and_spaces(): void {
        $list = DataItem::TYPE_LIST;
        $this->assertTrue( Operators::compare( $list, 'matches_any', [ ' A ', 'b' ], [ 'a', 'z' ] ) );
        $this->assertFalse( Operators::compare( $list, 'matches_any', [ 'b' ], [ 'a' ] ) );
        $this->assertTrue( Operators::compare( $list, 'matches_all', [ 'a', 'B', 'c' ], [ 'b', 'a' ] ) );
        $this->assertFalse( Operators::compare( $list, 'matches_all', [ 'a' ], [ 'a', 'b' ] ) );
        $this->assertTrue( Operators::compare( $list, 'matches_none', [ 'b' ], [ 'a' ] ) );
        $this->assertFalse( Operators::compare( $list, 'matches_none', [ 'A' ], [ 'a' ] ) );
    }

    public function test_an_empty_list_matches_none_only(): void {
        $list = DataItem::TYPE_LIST;
        $this->assertTrue( Operators::compare( $list, 'matches_none', [], [ 'a' ] ) );
        $this->assertFalse( Operators::compare( $list, 'matches_any', [], [ 'a' ] ) );
        $this->assertFalse( Operators::compare( $list, 'matches_all', [], [ 'a' ] ) );
    }

    public function test_is_any_of(): void {
        $this->assertTrue( Operators::compare( DataItem::TYPE_ENUM, 'is_any_of', 'US', [ 'us', 'ca' ] ) );
        $this->assertFalse( Operators::compare( DataItem::TYPE_ENUM, 'is_any_of', 'de', [ 'us', 'ca' ] ) );
        $this->assertFalse( Operators::compare( DataItem::TYPE_ENUM, 'is_any_of', '', [ 'us' ] ) );
    }

    public function test_is_set_takes_no_value(): void {
        $this->assertFalse( Operators::needs_value( 'is_set' ) );
        $this->assertFalse( Operators::needs_value( 'is_not_set' ) );
        $this->assertTrue( Operators::compare( DataItem::TYPE_DATE, 'is_set', '2026-01-02', '' ) );
        $this->assertFalse( Operators::compare( DataItem::TYPE_DATE, 'is_set', '', '' ) );
        $this->assertTrue( Operators::compare( DataItem::TYPE_DATE, 'is_not_set', '', '' ) );
        $this->assertFalse( Operators::compare( DataItem::TYPE_DATE, 'is_not_set', '2026-01-02', '' ) );
    }

    public function test_in_last_edges(): void {
        $now = 1000000;
        $this->assertTrue( Operators::in_last( $now, 1, 'hours', $now ) );
        $this->assertTrue( Operators::in_last( $now - 3600, 1, 'hours', $now ) );
        $this->assertFalse( Operators::in_last( $now - 3601, 1, 'hours', $now ) );
        $this->assertFalse( Operators::in_last( $now + 1, 1, 'hours', $now ) );
        $this->assertTrue( Operators::in_last( $now - 86400, 1, 'days', $now ) );
        $this->assertFalse( Operators::in_last( $now - 86401, 1, 'days', $now ) );
    }

    public function test_not_in_last_edges(): void {
        $now = 1000000;
        $this->assertFalse( Operators::not_in_last( $now - 3600, 1, 'hours', $now ) );
        $this->assertTrue( Operators::not_in_last( $now - 3601, 1, 'hours', $now ) );
        $this->assertFalse( Operators::not_in_last( $now - 86400, 1, 'days', $now ) );
        $this->assertTrue( Operators::not_in_last( $now - 86401, 1, 'days', $now ) );
    }

    public function test_no_date_matches_neither_relative_comparison(): void {
        $this->assertFalse( Operators::in_last( null, 1, 'days', 1000000 ) );
        $this->assertFalse( Operators::not_in_last( null, 1, 'days', 1000000 ) );
    }

    public function test_meta_compares_as_numbers_when_both_sides_are(): void {
        $meta = DataItem::TYPE_META;
        $this->assertTrue( Operators::compare( $meta, 'equal', '5.0', '5' ) );
        $this->assertFalse( Operators::compare( $meta, 'not_equal', '5.0', '5' ) );
        $this->assertTrue( Operators::compare( $meta, 'equal', 'Gold', 'gold' ) );
        $this->assertTrue( Operators::compare( $meta, 'not_equal', 'gold', '5' ) );
        $this->assertTrue( Operators::compare( $meta, 'greater_than', '10', '9' ) );
        $this->assertFalse( Operators::compare( $meta, 'greater_than', 'abc', '9' ) );
        $this->assertFalse( Operators::compare( $meta, 'less_than', '1', 'abc' ) );
        $this->assertTrue( Operators::compare( $meta, 'contains', 'Gold tier', 'gold' ) );
        $this->assertTrue( Operators::compare( $meta, 'is_empty', '', '' ) );
    }
}
