<?php
namespace YayMatik\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Conditions\Criteria;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Definition\GraphSchema;
use YayMatik\Workflow\Core\Definition\Messages;
use YayMatik\Workflow\Core\Definition\Validator;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Core\Steps\Trigger;
use YayMatik\Workflow\Extension\Diagnostics;
use YayMatik\Workflow\Extension\Extensions;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** Two plugins declare facts `amount` and `when` with other types: each workflow reads its own trigger's. */
final class FactsPerTriggerTest extends TestCase {

    /** @var Trigger[] By id: `acme_paid` (amount money, when date), `other_note` (amount string, when string). */
    private $triggers = [];

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_options'] = [];
        $declare                          = function ( $registrar ) {
            foreach ( [
                'acme_paid'  => [ 'money', 'date' ],
                'other_note' => [ 'string', 'string' ],
            ] as $id => list( $amount, $when ) ) {
                $registrar->module(
                    [
                        'id'    => strtok( $id, '_' ),
                        'label' => ucfirst( strtok( $id, '_' ) ),
                    ]
                )->trigger(
                    $id,
                    [
                        'label'       => 'T',
                        'description' => 'T.',
                        'provides'    => [ 'customer' ],
                        'facts'       => [
                            'amount' => [
                                'label' => 'Amount',
                                'type'  => $amount,
                            ],
                            'when'   => [
                                'label' => 'When',
                                'type'  => $when,
                            ],
                        ],
                    ]
                );
            }
        };
        foreach ( Extensions::collect( [], $declare ) as $module ) {
            foreach ( $module->triggers() as $trigger ) {
                $this->triggers[ $trigger->id() ] = $trigger;
            }
        }
    }

    /**
     * A condition config with one rule.
     *
     * @param string $operator
     * @param string $value
     */
    private static function config( string $operator, string $value ): array {
        return ( new Criteria() )->sanitize(
            [
                'rules' => [
                    [
                        'field'    => 'trigger.amount',
                        'operator' => $operator,
                        'value'    => $value,
                    ],
                ],
            ]
        );
    }

    /**
     * A run's scope with these facts, of this trigger.
     *
     * @param array       $facts
     * @param string|null $trigger
     */
    private function scope( array $facts, ?string $trigger ): Scope {
        $scope = new Scope( [ 'trigger' => $facts ], null, [] );
        $scope->set_trigger( null === $trigger ? null : $this->triggers[ $trigger ] );
        return $scope;
    }

    public function test_both_triggers_register_without_a_diagnostic(): void {
        $this->assertSame( [ 'acme_paid', 'other_note' ], array_keys( $this->triggers ) );
        $this->assertSame( [], Diagnostics::recent( 24 ) );
    }

    public function test_a_condition_compares_with_its_own_triggers_type(): void {
        $config = self::config( 'equal', '10' );
        $facts  = [ 'amount' => '10.0' ];

        $this->assertTrue( ( new Criteria() )->evaluate( $this->scope( $facts, 'acme_paid' ), $config ), 'money: 10.0 is 10' );
        $this->assertFalse( ( new Criteria() )->evaluate( $this->scope( $facts, 'other_note' ), $config ), 'text: "10.0" isn\'t "10"' );
    }

    public function test_a_date_fact_is_read_only_from_its_own_trigger(): void {
        $facts = [ 'when' => '2026-11-14' ];

        $this->assertSame( ( new \DateTimeImmutable( '2026-11-14', wp_timezone() ) )->getTimestamp(), $this->scope( $facts, 'acme_paid' )->timestamp( 'trigger.when' ) );
        $this->assertNull( $this->scope( $facts, 'other_note' )->timestamp( 'trigger.when' ), 'a text fact of the same key' );
        $this->assertNull( $this->scope( $facts, null )->timestamp( 'trigger.when' ), 'no trigger' );
        $this->assertNull( $this->scope( $facts, 'acme_paid' )->timestamp( 'steps.a.date' ) );
    }

    public function test_the_validator_checks_a_rule_against_the_workflows_trigger(): void {
        $issue = function ( string $trigger, array $config ): ?array {
            $graph  = GraphSchema::prepare(
                [
                    'nodes' => [ self::node( 't', 'trigger', $trigger ), self::node( 'c', 'condition', 'condition', $config ) ],
                    'edges' => [
                        [
                            'id'     => 't-c',
                            'source' => 't',
                            'target' => 'c',
                        ],
                    ],
                ]
            );
            $errors = Validator::errors(
                [
                    'name'  => 'A',
                    'graph' => $graph,
                ]
            );
            return array_column( $errors, null, 'nodeId' )['c'] ?? null;
        };
        $steps  = new \ReflectionProperty( StepRegistry::class, 'steps' );
        $steps->setAccessible( true );
        $before = StepRegistry::all();
        $steps->setValue( null, $before + $this->triggers );
        try {
            $issues = [
                $issue( 'acme_paid', self::config( 'greater_than', '10' ) ),
                $issue( 'other_note', self::config( 'contains', 'vip' ) ),
                $issue( 'other_note', self::config( 'greater_than', '10' ) ),
                $issue( 'acme_paid', self::config( 'contains', 'vip' ) ),
                $issue( 'order_created', self::config( 'equal', '10' ) ),
            ];
        } finally {
            $steps->setValue( null, $before );
        }

        $this->assertSame(
            [ null, null, Messages::get( 'ruleComparison' ), Messages::get( 'ruleComparison' ), Messages::get( 'ruleVariable' ) ],
            array_map( fn( ?array $issue ) => $issue['message'] ?? null, $issues )
        );
        $this->assertSame( [ 0 ], $issues[2]['rule'], 'drawn under its rule' );
    }

    /**
     * @param string $id
     * @param string $kind
     * @param string $block
     * @param array  $config
     */
    private static function node( string $id, string $kind, string $block, array $config = [] ): array {
        return [
            'id'       => $id,
            'kind'     => $kind,
            'position' => [
                'x' => 0,
                'y' => 0,
            ],
            'data'     => [
                'block'  => $block,
                'config' => $config,
            ],
        ];
    }
}
