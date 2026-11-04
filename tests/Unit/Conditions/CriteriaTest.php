<?php
namespace YayMatik\Tests\Unit\Conditions;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeEntity;
use YayMatik\Workflow\Conditions\Criteria;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Definition\Messages;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** Rules: their stored shape, their checks and how they evaluate. */
final class CriteriaTest extends TestCase {

    /**
     * @param string       $field
     * @param string       $operator
     * @param string|array $value
     * @param array        $extra
     */
    private static function rule( string $field, string $operator, $value = '', array $extra = [] ): array {
        return [
            'field'     => $field,
            'operator'  => $operator,
            'value'     => $value,
            'valueType' => 'value',
        ] + $extra;
    }

    /**
     * The first issue of one rule, or true.
     *
     * @param array $rule
     * @return true|string
     */
    private static function check( array $rule ) {
        $criteria = new Criteria();
        $valid    = $criteria->validate(
            $criteria->sanitize(
                [
                    'match' => 'all',
                    'rules' => [ $rule ],
                ]
            )
        );
        return true === $valid ? true : $valid->get_error_message();
    }

    public function test_a_plain_rule_comes_out_byte_identical(): void {
        $config = [
            'match' => 'any',
            'rules' => [
                self::rule( 'order.total', 'greater_or_equal', '500' ),
                [
                    'field'     => 'order.total',
                    'operator'  => 'less_than',
                    'value'     => 'order.subtotal',
                    'valueType' => 'variable',
                ],
            ],
        ];
        $this->assertSame( wp_json_encode( $config ), wp_json_encode( ( new Criteria() )->sanitize( $config ) ) );
    }

    public function test_a_list_comparison_keeps_a_clean_list(): void {
        $clean = ( new Criteria() )->sanitize(
            [
                'rules' => [
                    self::rule( 'order.status', 'is_any_of', [ ' processing ', 'on-hold', 'processing', '', [ 'x' ] ], [ 'valueType' => 'variable' ] ),
                ],
            ]
        );
        $this->assertSame( self::rule( 'order.status', 'is_any_of', [ 'processing', 'on-hold' ] ), $clean['rules'][0] );
    }

    public function test_an_array_value_is_dropped_on_a_comparison_that_takes_one_value(): void {
        $clean = ( new Criteria() )->sanitize( [ 'rules' => [ self::rule( 'order.total', 'equal', [ '1', '2' ] ) ] ] );
        $this->assertSame( '', $clean['rules'][0]['value'] );
    }

    public function test_a_relative_date_keeps_its_unit_and_defaults_to_days(): void {
        $criteria = new Criteria();
        $hours    = $criteria->sanitize( [ 'rules' => [ self::rule( 'order.created_date', 'in_last', '3', [ 'unit' => 'hours' ] ) ] ] );
        $unknown  = $criteria->sanitize( [ 'rules' => [ self::rule( 'order.created_date', 'in_last', '3', [ 'unit' => 'years' ] ) ] ] );
        $this->assertSame( 'hours', $hours['rules'][0]['unit'] );
        $this->assertSame( 'days', $unknown['rules'][0]['unit'] );
    }

    public function test_a_unit_is_dropped_on_other_comparisons(): void {
        $clean = ( new Criteria() )->sanitize( [ 'rules' => [ self::rule( 'order.total', 'equal', '1', [ 'unit' => 'hours' ] ) ] ] );
        $this->assertArrayNotHasKey( 'unit', $clean['rules'][0] );
    }

    /** @return array[] */
    public function bad_amounts(): array {
        return [ [ '' ], [ '0' ], [ '-1' ], [ '1.5' ], [ 'abc' ] ];
    }

    /**
     * @dataProvider bad_amounts
     * @param string $amount
     */
    public function test_a_relative_date_needs_a_whole_number( string $amount ): void {
        $this->assertSame( Messages::get( 'ruleAmount' ), self::check( self::rule( 'order.created_date', 'in_last', $amount ) ) );
    }

    public function test_good_new_rules_pass(): void {
        $this->assertTrue( self::check( self::rule( 'order.created_date', 'not_in_last', '30', [ 'unit' => 'days' ] ) ) );
        $this->assertTrue( self::check( self::rule( 'order.created_date', 'is_set' ) ) );
        $this->assertTrue( self::check( self::rule( 'order.status', 'is_any_of', [ 'processing' ] ) ) );
    }

    public function test_a_list_comparison_needs_a_value_from_the_options(): void {
        $this->assertSame( Messages::get( 'ruleListRequired' ), self::check( self::rule( 'order.status', 'is_any_of', [] ) ) );
        $this->assertSame( Messages::get( 'ruleOption' ), self::check( self::rule( 'order.status', 'is_any_of', [ 'nope' ] ) ) );
    }

    public function test_a_comparison_of_another_type_is_refused(): void {
        $this->assertSame( Messages::get( 'ruleComparison' ), self::check( self::rule( 'order.total', 'in_last', '3' ) ) );
    }

    /**
     * @param string $when
     * @param array  $rule
     */
    private static function evaluate( string $when, array $rule ): bool {
        $thing = new FakeEntity(
            'order',
            [
                'created_date' => [
                    'label'     => 'Date',
                    'type'      => DataItem::TYPE_DATE,
                    'value'     => fn( $order ) => $order->date,
                    'timestamp' => fn( $order ) => '' === $order->date ? null : strtotime( $order->date ),
                ],
            ]
        );
        $scope = new Scope(
            [ 'trigger' => [ 'order' => new Ref( 'order', '1' ) ] ],
            new RefResolver( [ 'order' => fn() => (object) [ 'date' => $when ] ] ),
            [ 'order' => $thing ]
        );
        return ( new Criteria() )->evaluate(
            $scope,
            [
                'match' => 'all',
                'rules' => [ $rule ],
            ]
        );
    }

    public function test_relative_dates_evaluate(): void {
        $in_last     = self::rule( 'order.created_date', 'in_last', '3', [ 'unit' => 'hours' ] );
        $not_in_last = self::rule( 'order.created_date', 'not_in_last', '3', [ 'unit' => 'hours' ] );
        $this->assertTrue( self::evaluate( '-1 hour', $in_last ) );
        $this->assertFalse( self::evaluate( '-4 hours', $in_last ) );
        $this->assertTrue( self::evaluate( '-4 hours', $not_in_last ) );
        $this->assertFalse( self::evaluate( '', $not_in_last ) );
        $this->assertFalse( self::evaluate( '', $in_last ) );
    }

    public function test_is_set_evaluates(): void {
        $this->assertTrue( self::evaluate( '2026-01-02', self::rule( 'order.created_date', 'is_set' ) ) );
        $this->assertTrue( self::evaluate( '', self::rule( 'order.created_date', 'is_not_set' ) ) );
    }
}
