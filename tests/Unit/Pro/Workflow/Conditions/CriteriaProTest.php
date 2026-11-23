<?php
namespace YayMatik\Tests\Unit\Pro\Workflow\Conditions;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeEntity;
use YayMatik\Pro\Workflow\Conditions\CriteriaPro;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Definition\Messages;

require_once dirname( __DIR__, 4 ) . '/Support/woocommerce-stubs.php';

/** Pro condition rules: compare with another field, custom fields, groups. */
final class CriteriaProTest extends TestCase {

    /** @var mixed */
    private $wpdb;

    protected function setUp(): void {
        $this->wpdb = $GLOBALS['wpdb'] ?? null;
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here; MetaKeys reads its prefix.
        $GLOBALS['wpdb'] = (object) [ 'base_prefix' => 'wp_' ];
    }

    protected function tearDown(): void {
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- puts back what setUp() replaced.
        $GLOBALS['wpdb'] = $this->wpdb;
    }

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
        $criteria = new CriteriaPro();
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
        $this->assertSame( wp_json_encode( $config ), wp_json_encode( ( new CriteriaPro() )->sanitize( $config ) ) );
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
        return ( new CriteriaPro() )->evaluate(
            $scope,
            [
                'match' => 'all',
                'rules' => [ $rule ],
            ]
        );
    }

    public function test_a_custom_field_rule_keeps_its_key_and_takes_no_variable(): void {
        $clean = ( new CriteriaPro() )->sanitize(
            [
                'rules' => [
                    [
                        'field'     => 'order.meta',
                        'operator'  => 'equal',
                        'value'     => 'x',
                        'valueType' => 'variable',
                        'key'       => ' _vip ',
                    ],
                ],
            ]
        );
        $this->assertSame( self::rule( 'order.meta', 'equal', 'x', [ 'key' => '_vip' ] ), $clean['rules'][0] );
    }

    public function test_a_custom_field_rule_needs_a_key_it_can_read(): void {
        $this->assertSame( Messages::get( 'ruleKeyRequired' ), self::check( self::rule( 'order.meta', 'equal', 'x' ) ) );
        $this->assertSame( Messages::get( 'ruleMetaKey' ), self::check( self::rule( 'order.meta', 'equal', 'x', [ 'key' => '_order_total' ] ) ) );
        $this->assertSame( Messages::get( 'ruleMetaKey' ), self::check( self::rule( 'customer.meta', 'equal', 'x', [ 'key' => 'session_tokens' ] ) ) );
        $this->assertSame( Messages::get( 'ruleMetaKey' ), self::check( self::rule( 'customer.meta', 'is_empty', '', [ 'key' => 'WP_CAPABILITIES' ] ) ) );
        $this->assertSame( Messages::get( 'ruleMetaKey' ), self::check( self::rule( 'customer.meta', 'equal', 'x', [ 'key' => 'wp_2_capabilities' ] ) ) );
        $this->assertTrue( self::check( self::rule( 'customer.meta', 'equal', 'x', [ 'key' => 'tier' ] ) ) );
    }

    public function test_a_rule_issue_names_the_rule_it_is_about(): void {
        $criteria = new CriteriaPro();
        $path     = function ( array $rules ) use ( $criteria ) {
            $error = $criteria->validate(
                $criteria->sanitize(
                    [
                        'match' => 'all',
                        'rules' => $rules,
                    ]
                )
            );
            return $error->get_error_data();
        };
        $ok       = self::rule( 'order.total', 'greater_than', '1' );
        $blocked  = self::rule( 'customer.meta', 'is_empty', '', [ 'key' => 'wp_capabilities' ] );

        $this->assertSame(
            [
                'field' => 'rules',
                'rule'  => [ 1 ],
            ],
            $path( [ $ok, $blocked ] )
        );
        $this->assertSame(
            [
                'field' => 'rules',
                'rule'  => [ 1, 1 ],
            ],
            $path(
                [
                    $ok,
                    [
                        'match' => 'any',
                        'rules' => [ $ok, $blocked ],
                    ],
                ]
            )
        );
        $this->assertSame(
            [
                'field' => 'rules',
                'rule'  => [ 1 ],
            ],
            $path(
                [
                    $ok,
                    [
                        'match' => 'any',
                        'rules' => [],
                    ],
                ]
            ),
            'an empty group is about the group'
        );
    }

    public function test_custom_field_comparisons_evaluate(): void {
        $thing = new FakeEntity(
            'order',
            [
                'meta' => [
                    'label' => 'Custom',
                    'type'  => DataItem::TYPE_META,
                    'value' => fn() => '',
                    'meta'  => fn( $order, string $key ) => 'tier' === $key ? '5.0' : '',
                ],
            ]
        );
        $scope = new Scope(
            [ 'trigger' => [ 'order' => new Ref( 'order', '1' ) ] ],
            new RefResolver( [ 'order' => fn() => (object) [] ] ),
            [ 'order' => $thing ]
        );
        $check = fn( string $operator, string $value, string $key = 'tier' ) => ( new CriteriaPro() )->evaluate(
            $scope,
            [
                'match' => 'all',
                'rules' => [ self::rule( 'order.meta', $operator, $value, [ 'key' => $key ] ) ],
            ]
        );
        $this->assertTrue( $check( 'equal', '5' ) );
        $this->assertTrue( $check( 'greater_than', '4' ) );
        $this->assertFalse( $check( 'greater_than', 'abc' ) );
        $this->assertTrue( $check( 'is_empty', '', 'other' ) );
    }

    public function test_a_comparison_with_another_field_reads_both(): void {
        $number = fn( string $label, string $key ) => [
            'label' => $label,
            'type'  => DataItem::TYPE_NUMBER,
            'value' => fn( $order ) => $order->$key,
        ];
        $thing  = new FakeEntity(
            'order',
            [
                'total'      => $number( 'Total', 'total' ),
                'subtotal'   => $number( 'Subtotal', 'subtotal' ),
                'paid_total' => $number( 'Paid', 'paid' ),
            ]
        );
        $scope  = new Scope(
            [ 'trigger' => [ 'order' => new Ref( 'order', '1' ) ] ],
            new RefResolver(
                [
                    'order' => fn() => (object) [
                        'total'    => 99.5,
                        'subtotal' => 90.0,
                        'paid'     => null,
                    ],
                ]
            ),
            [ 'order' => $thing ]
        );
        $check  = fn( string $operator, string $other ) => ( new CriteriaPro() )->evaluate(
            $scope,
            [
                'match' => 'all',
                'rules' => [ array_replace( self::rule( 'order.total', $operator, $other ), [ 'valueType' => 'variable' ] ) ],
            ]
        );

        $this->assertTrue( $check( 'greater_than', 'order.subtotal' ) );
        $this->assertFalse( $check( 'less_than', 'order.subtotal' ) );
        $this->assertFalse( $check( 'not_equal', 'order.paid_total' ), 'no value to compare with never matches' );
    }

    public function test_a_blank_key_is_reported_before_a_blocked_one_and_before_the_value(): void {
        $this->assertSame( Messages::get( 'ruleKeyRequired' ), self::check( self::rule( 'order.meta', 'equal', '' ) ) );
    }

    public function test_meta_keys_lists_the_names_on_that_field_only(): void {
        $config = [
            'rules' => [
                self::rule( 'customer.meta', 'equal', 'x', [ 'key' => 'vip' ] ),
                self::rule( 'order.meta', 'equal', 'x', [ 'key' => '_gift' ] ),
                self::rule( 'customer.meta', 'equal', 'x', [ 'key' => '' ] ),
                self::rule( 'order.total', 'equal', '1' ),
            ],
        ];
        $this->assertSame( [ 'vip' ], CriteriaPro::meta_keys( $config, 'customer.meta' ) );
    }

    /**
     * @param string $match
     * @param array  $rules
     */
    private static function group( string $match, array $rules ): array { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.matchFound -- the stored key's name.
        return [
            'match' => $match,
            'rules' => $rules,
        ];
    }

    public function test_a_group_keeps_its_match_and_clean_rules(): void {
        $clean = ( new CriteriaPro() )->sanitize(
            [
                'rules' => [
                    self::group( 'any', [ self::rule( 'order.total', 'equal', ' 1 ' ) ] ),
                    self::group( 'nope', [] ),
                ],
            ]
        );
        $this->assertSame( self::group( 'any', [ self::rule( 'order.total', 'equal', '1' ) ] ), $clean['rules'][0] );
        $this->assertSame( self::group( 'all', [] ), $clean['rules'][1] );
    }

    public function test_a_group_inside_a_group_is_dropped(): void {
        $clean = ( new CriteriaPro() )->sanitize(
            [
                'rules' => [
                    self::group(
                        'all',
                        [
                            self::rule( 'order.total', 'equal', '1' ),
                            self::group( 'any', [ self::rule( 'order.total', 'equal', '2' ) ] ),
                        ]
                    ),
                ],
            ]
        );
        $this->assertSame( [ self::rule( 'order.total', 'equal', '1' ) ], $clean['rules'][0]['rules'] );
    }

    /**
     * The first issue of a config of these items, or true.
     *
     * @param array $items
     * @return true|string
     */
    private static function check_items( array $items ) {
        $criteria = new CriteriaPro();
        $valid    = $criteria->validate(
            $criteria->sanitize(
                [
                    'match' => 'all',
                    'rules' => $items,
                ]
            )
        );
        return true === $valid ? true : $valid->get_error_message();
    }

    public function test_group_checks(): void {
        $good = self::rule( 'order.total', 'equal', '1' );
        $this->assertTrue( self::check_items( [ self::group( 'any', [ $good ] ), $good ] ) );
        $this->assertSame( Messages::get( 'groupRulesRequired' ), self::check_items( [ $good, self::group( 'all', [] ) ] ), 'an empty group' );
        $this->assertSame( Messages::get( 'groupRulesRequired' ), self::check_items( [ $good, self::group( 'any', [ self::rule( '', '' ) ] ) ] ), 'a group with a blank rule' );
        $this->assertSame( Messages::get( 'rulesRequired' ), self::check_items( [ self::group( 'all', [] ) ] ), 'only an empty group' );
        $this->assertSame( Messages::get( 'ruleNumber' ), self::check_items( [ self::group( 'all', [ self::rule( 'order.total', 'equal', 'abc' ) ] ) ] ), 'a rule inside a group' );
    }

    public function test_uses_reads_through_groups(): void {
        $criteria = new CriteriaPro();
        $config   = $criteria->sanitize( [ 'rules' => [ self::group( 'all', [ self::rule( 'customer.email', 'is_empty' ) ] ), self::rule( 'order.total', 'equal', '1' ) ] ] );
        $uses     = $criteria->uses( $config );
        sort( $uses );
        $this->assertSame( [ 'customer', 'order' ], $uses );
    }

    public function test_meta_keys_read_through_groups(): void {
        $config = [ 'rules' => [ self::group( 'all', [ self::rule( 'customer.meta', 'equal', 'x', [ 'key' => 'vip' ] ) ] ) ] ];
        $this->assertSame( [ 'vip' ], CriteriaPro::meta_keys( $config, 'customer.meta' ) );
    }

    public function test_groups_evaluate_with_their_own_match(): void {
        $yes = self::rule( 'order.created_date', 'is_not_set' );
        $no  = self::rule( 'order.created_date', 'is_set' );
        // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.matchFound -- the stored key's name.
        $scope = fn( array $items, string $match ) => ( new CriteriaPro() )->evaluate(
            new Scope(
                [ 'trigger' => [ 'order' => new Ref( 'order', '1' ) ] ],
                new RefResolver( [ 'order' => fn() => (object) [] ] ),
                [
                    'order' => new FakeEntity(
                        'order',
                        [
                            'created_date' => [
                                'label' => 'Date',
                                'type'  => DataItem::TYPE_DATE,
                                'value' => fn() => '',
                            ],
                        ]
                    ),
                ]
            ),
            [
                'match' => $match,
                'rules' => $items,
            ]
        );
        $this->assertTrue( $scope( [ self::group( 'any', [ $no, $yes ] ), $yes ], 'all' ), 'any inside all' );
        $this->assertFalse( $scope( [ self::group( 'all', [ $no, $yes ] ), $yes ], 'all' ), 'all inside all' );
        $this->assertTrue( $scope( [ self::group( 'all', [ $no, $yes ] ), $yes ], 'any' ), 'the rule beside it' );
        $this->assertFalse( $scope( [ self::group( 'all', [ $no, $yes ] ), $no ], 'any' ), 'neither' );
    }

    /**
     * A rule on the order's exact created date, under a trigger whose `time` fact is a Unix time.
     *
     * @param string     $created  `created_date` as strtotime() reads it; '' for none.
     * @param int|string $time     The stored `trigger.time`.
     * @param array      $rule
     */
    private static function against_trigger_time( string $created, $time, array $rule ): bool {
        $trigger = \YayMatik\Workflow\Extension\Extensions::collect(
            [],
            fn( $registrar ) => $registrar->module(
                [
                    'id'    => 'acme',
                    'label' => 'Acme',
                ]
            )->trigger(
                'acme_signed_in',
                [
                    'label'       => 'Signed in',
                    'description' => 'When someone signs in.',
                    'provides'    => [ 'order' ],
                    'facts'       => [
                        'time' => [
                            'label' => 'Trigger time',
                            'type'  => 'date',
                        ],
                    ],
                ]
            )
        )[0]->triggers()[0];
        $scope   = new Scope(
            [
                'trigger' => [
                    'order' => new Ref( 'order', '1' ),
                    'time'  => $time,
                ],
            ],
            new RefResolver( [ 'order' => fn() => (object) [ 'date' => $created ] ] ),
            [
                'order' => new FakeEntity(
                    'order',
                    [
                        'created_date' => [
                            'label'     => 'Date',
                            'type'      => DataItem::TYPE_DATE,
                            'value'     => fn( $order ) => '' === $order->date ? '' : gmdate( 'Y-m-d', (int) strtotime( $order->date . ' UTC' ) ),
                            'timestamp' => fn( $order ) => '' === $order->date ? null : (int) strtotime( $order->date . ' UTC' ),
                        ],
                    ]
                ),
            ]
        );
        $scope->set_trigger( $trigger );
        return ( new CriteriaPro() )->evaluate(
            $scope,
            [
                'match' => 'all',
                'rules' => [ $rule ],
            ]
        );
    }

    public function test_two_exact_moments_compare_by_time_within_the_same_day(): void {
        $time   = gmmktime( 15, 0, 0, 11, 18, 2026 );
        $before = [ 'valueType' => 'variable' ] + self::rule( 'order.created_date', 'before', 'trigger.time' );
        $after  = [ 'valueType' => 'variable' ] + self::rule( 'order.created_date', 'after', 'trigger.time' );

        $this->assertTrue( self::against_trigger_time( '2026-11-18 09:00:00', $time, $before ), 'same day, earlier: before' );
        $this->assertFalse( self::against_trigger_time( '2026-11-18 09:00:00', $time, $after ) );
        $this->assertFalse( self::against_trigger_time( '2026-11-18 15:00:00', $time, $before ), 'same second: not before' );
        $this->assertFalse( self::against_trigger_time( '2026-11-18 15:00:00', $time, $after ), 'same second: not after' );
        $this->assertTrue( self::against_trigger_time( '2026-11-18 15:00:01', $time, $after ) );
    }

    public function test_a_day_only_side_compares_by_day(): void {
        $before = [ 'valueType' => 'variable' ] + self::rule( 'order.created_date', 'before', 'trigger.time' );

        $this->assertFalse( self::against_trigger_time( '2026-11-18 09:00:00', '2026-11-18', $before ), 'a Y-m-d fact: the same day is not before' );
        $this->assertTrue( self::against_trigger_time( '2026-11-17 23:00:00', '2026-11-18', $before ) );
    }

    public function test_a_fixed_value_rule_on_the_trigger_time_compares_by_day(): void {
        $time = gmmktime( 15, 0, 0, 11, 18, 2026 );

        $this->assertFalse( self::against_trigger_time( '', $time, self::rule( 'trigger.time', 'before', '2026-11-18' ) ) );
        $this->assertTrue( self::against_trigger_time( '', $time, self::rule( 'trigger.time', 'before', '2026-11-19' ) ) );
        $this->assertTrue( self::against_trigger_time( '', $time, self::rule( 'trigger.time', 'after', '2026-11-17' ) ) );
    }

    public function test_no_compare_when_the_field_has_no_value(): void {
        $time = gmmktime( 15, 0, 0, 11, 18, 2026 );

        $this->assertFalse( self::against_trigger_time( '', $time, [ 'valueType' => 'variable' ] + self::rule( 'order.created_date', 'before', 'trigger.time' ) ) );
    }
}
