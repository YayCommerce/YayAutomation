<?php
namespace YayMatik\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Extension\Diagnostics;
use YayMatik\Workflow\Extension\ExtensionAction;
use YayMatik\Workflow\Extension\Extensions;

/** Field arrays and type names become core fields and types; a bad declaration is refused alone. */
final class DeclarationTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_options'] = [];
    }

    /**
     * The action `acme_act` with these args, or null when refused.
     *
     * @param array $args
     */
    private static function action( array $args ): ?ExtensionAction {
        $module = Extensions::collect(
            [],
            function ( $registrar ) use ( $args ) {
                $registrar->module(
                    [
                        'id'    => 'acme',
                        'label' => 'Acme',
                    ]
                )->action(
                    'acme_act',
                    $args + [
                        'label'       => 'Act',
                        'description' => 'Does it.',
                        'handle'      => fn( array $config, $step ) => $step->done(),
                    ]
                );
            }
        )[0];
        return $module->actions()[0] ?? null;
    }

    /**
     * @param array $fields
     * @return array[] Field key => its client shape.
     */
    private static function fields( array $fields ): array {
        $shapes = [];
        foreach ( self::action( [ 'settings' => $fields ] )->fields() as $field ) {
            $shapes[ $field->key() ] = $field->to_array();
        }
        return $shapes;
    }

    private static function last_diagnostic(): string {
        return Diagnostics::recent( 24 )[0]['message'] ?? '';
    }

    public function test_an_unknown_key_is_kept_out_but_reported_to_the_check_not_health_check(): void {
        $recorded = new \ReflectionProperty( Diagnostics::class, 'recorded' );
        $recorded->setAccessible( true );
        $recorded->setValue( null, [] );
        $this->assertNotNull( self::action( [ 'descripton' => 'Typo.' ] ) );

        $this->assertContains(
            [
                'module'   => 'acme',
                'callback' => 'action acme_act',
                'message'  => 'Unknown keys ignored: descripton.',
            ],
            Diagnostics::recorded()
        );
        $this->assertSame( [], Diagnostics::recent( 24 ) );
    }

    public function test_field_arrays_become_core_fields(): void {
        $fields = self::fields(
            [
                'role'    => [
                    'type'     => 'select',
                    'label'    => 'Role',
                    'options'  => fn() => [
                        'silver' => 'Silver',
                        'gold'   => 'Gold',
                    ],
                    'required' => 'Pick a role for {{ step }}.',
                    'default'  => 'silver',
                ],
                'note'    => [
                    'type'        => 'text',
                    'label'       => 'Note',
                    'variables'   => true,
                    'placeholder' => 'Thanks!',
                    'help'        => 'Shown to the customer.',
                    'show_if'     => [ 'role' => [ 'gold' ] ],
                ],
                'points'  => [
                    'type'     => 'number',
                    'label'    => 'Points',
                    'min'      => 1,
                    'max'      => 1000,
                    'required' => true,
                ],
                'delay'   => [
                    'type'  => 'number_unit',
                    'label' => 'Delay',
                    'units' => [
                        'hours' => 'Hours',
                        'days'  => 'Days',
                    ],
                ],
                'tip'     => [
                    'type'  => 'notice',
                    'label' => 'Tip',
                    'text'  => 'Points never expire.',
                ],
                'notify'  => [
                    'type'  => 'toggle',
                    'label' => 'Notify',
                ],
                'copy_to' => [
                    'type'  => 'emails',
                    'label' => 'Copy to',
                ],
            ]
        );

        $this->assertSame( [ 'role', 'note', 'points', 'delay', 'tip', 'notify', 'copy_to' ], array_keys( $fields ) );
        $this->assertSame( [ 'select', true, 'Pick a role for {{ step }}.', 'silver' ], [ $fields['role']['type'], $fields['role']['required'], $fields['role']['requiredMessage'], $fields['role']['default'] ] );
        $this->assertSame( [ 'silver', 'gold' ], array_column( $fields['role']['options'], 'value' ) );
        $this->assertSame( [ true, 'Thanks!', 'Shown to the customer.' ], [ $fields['note']['variables'], $fields['note']['placeholder'], $fields['note']['help'] ] );
        $this->assertSame(
            [
                'field' => 'role',
                'value' => [ 'gold' ],
            ],
            $fields['note']['showIf']
        );
        $this->assertSame( [ 1, 1000, true ], [ $fields['points']['min'], $fields['points']['max'], $fields['points']['required'] ] );
        $this->assertArrayNotHasKey( 'requiredMessage', $fields['points'] );
        $this->assertSame( 'hours', $fields['delay']['default']['unit'] );
        $this->assertSame( 'notice', $fields['tip']['type'] );
    }

    public function test_a_required_field_left_blank_fails_validate_with_its_message(): void {
        $action = self::action(
            [
                'settings' => [
                    'points' => [
                        'type'     => 'number',
                        'label'    => 'Points',
                        'required' => 'Enter the points for {{ step }}.',
                    ],
                ],
            ]
        );

        $this->assertTrue( is_wp_error( $action->validate( $action->sanitize( [] ) ) ) );
        $this->assertTrue( $action->validate( $action->sanitize( [ 'points' => 10 ] ) ) );
    }

    public function test_a_throwing_options_callable_gives_no_options_and_a_diagnostic(): void {
        $fields = self::fields(
            [
                'role' => [
                    'type'    => 'select',
                    'label'   => 'Role',
                    'options' => function () {
                        throw new \RuntimeException( 'Roles table missing.' );
                    },
                ],
            ]
        );

        $this->assertSame( [], $fields['role']['options'] );
        $this->assertSame( 'Roles table missing.', self::last_diagnostic() );
    }

    public function test_type_names_become_core_types(): void {
        $names   = [
            'a' => [ 'type' => 'string' ],
            'b' => [ 'type' => 'number' ],
            'c' => [ 'type' => 'money' ],
            'd' => [ 'type' => 'boolean' ],
            'e' => [ 'type' => 'date' ],
            'f' => [ 'type' => 'enum' ],
            'g' => [ 'type' => 'list' ],
            'h' => [
                'type' => 'list',
                'of'   => 'number',
            ],
            'i' => [
                'type' => 'entity',
                'item' => 'order',
            ],
        ];
        $outputs = [];
        foreach ( $names as $key => $type ) {
            $outputs[ $key ] = $type + [ 'label' => strtoupper( $key ) ];
        }

        $types = array_map( fn( array $output ) => $output['type']->to_string(), self::action( [ 'outputs' => $outputs ] )->outputs() );

        $this->assertSame(
            [
                'a' => 'string',
                'b' => 'number',
                'c' => 'money',
                'd' => 'boolean',
                'e' => 'date',
                'f' => 'enum',
                'g' => 'list<string>',
                'h' => 'list<number>',
                'i' => 'entity<order>',
            ],
            $types
        );
    }

    public function test_bad_declarations_are_refused_with_a_diagnostic(): void {
        $cases = [
            'Output "total" must be lowercase key => [ label, type ], with a known type name.' => [
                'outputs' => [
                    'total' => [
                        'label' => 'Total',
                        'type'  => 'float',
                    ],
                ],
            ],
            'Output "delivery" is reserved for YayMatik.' => [
                'outputs' => [
                    'delivery' => [
                        'label' => 'Delivery',
                        'type'  => 'string',
                    ],
                ],
            ],
            'Setting "role" needs a "type" from: text, textarea, html, number, select, multiselect, toggle, emails, notice, number_unit.' => [ 'settings' => [ 'role' => [ 'label' => 'Role' ] ] ],
            'Setting "role" needs "options".'             => [
                'settings' => [
                    'role' => [
                        'type'  => 'select',
                        'label' => 'Role',
                    ],
                ],
            ],
            'Setting key "Role" must be lowercase letters, digits or underscores.' => [
                'settings' => [
                    'Role' => [
                        'type'  => 'text',
                        'label' => 'Role',
                    ],
                ],
            ],
            'Setting "role": "show_if" must be [ setting => value ].' => [
                'settings' => [
                    'role' => [
                        'type'    => 'text',
                        'label'   => 'Role',
                        'show_if' => [ 'a', 'b' ],
                    ],
                ],
            ],
            '"handle" must be a callable.'                => [ 'handle' => 'no_such_function' ],
            '"on_error" must be "stop" or "continue".'    => [ 'on_error' => 'retry' ],
            '"category" must be a core category or one this module declared first.' => [ 'category' => 'rewards' ],
            'Unknown data item in "needs": ordr.'         => [ 'needs' => [ 'ordr' ] ],
        ];
        foreach ( $cases as $message => $args ) {
            $GLOBALS['yaymatik_test_options'] = [];
            $this->assertNull( self::action( $args ), $message );
            $this->assertSame( $message, self::last_diagnostic() );
        }
    }

    public function test_a_bad_placeholder_keeps_the_step_with_a_diagnostic(): void {
        // The check reads translated text: a bad translation must not drop a step.
        $cases = [
            '"summary": {{ order.total }} isn\'t a placeholder; use {{ config.<field> }}. Kept as written.' => [ 'summary' => 'Add {{ config.points }} for {{ order.total }}' ],
            '"summary": {{ config.points | upper }} isn\'t a placeholder; use {{ config.<field> }}. Kept as written.' => [ 'summary' => '{{ config.points | upper }}' ],
            '"result": {{ steps.a.b }} isn\'t a placeholder; use {{ output.<key> }} or {{ config.<field> }}. Kept as written.' => [ 'result' => '{{ output.balance }} {{ steps.a.b }}' ],
            'Setting "role": "required": {{ config.role }} isn\'t a placeholder; use {{ step }}. Kept as written.' => [
                'settings' => [
                    'role' => [
                        'type'     => 'text',
                        'label'    => 'Role',
                        'required' => '{{ config.role }} is blank.',
                    ],
                ],
            ],
        ];
        foreach ( $cases as $message => $args ) {
            $GLOBALS['yaymatik_test_options'] = [];
            $action                           = self::action( $args );
            $this->assertNotNull( $action, $message );
            $this->assertSame( $message, self::last_diagnostic() );
        }
        $this->assertSame( '{{ config.points | upper }}', self::action( [ 'summary' => '{{ config.points | upper }}' ] )->summary(), 'the text stays as written' );
    }

    public function test_a_trigger_provides_what_a_payload_gives_and_once_per_is_subject(): void {
        $refused = [];
        Extensions::collect(
            [],
            function ( $registrar ) use ( &$refused ) {
                $module    = $registrar->module(
                    [
                        'id'    => 'acme',
                        'label' => 'Acme',
                    ]
                );
                $base      = [
                    'label'       => 'Points',
                    'description' => 'When points are earned.',
                ];
                $refused[] = $module->trigger( 'acme_one', $base );
                $refused[] = $module->trigger( 'acme_two', $base + [ 'provides' => 'customer' ] );
                $refused[] = $module->trigger(
                    'acme_three',
                    $base + [
                        'provides' => [ 'customer' ],
                        'once_per' => 'day',
                    ]
                );
                $refused[] = $module->trigger( 'acme_four', $base + [ 'provides' => [] ] );
                $refused[] = $module->trigger( 'acme_five', $base + [ 'provides' => [ 'product' ] ] );
                $refused[] = $module->trigger( 'acme_six', $base + [ 'provides' => [ 'order', 'customer' ] ] );
            }
        );

        $this->assertSame( [ false, false, false, false, false, true ], $refused );
        $this->assertSame( '"provides" must list data items a payload gives: customer, order, cart, review (declare a data item before the trigger that provides it).', Diagnostics::recent( 24 )[1]['message'] ?? '' );
    }

    public function test_fact_keys_are_not_ids_and_are_per_trigger(): void {
        $added = [];
        Extensions::collect(
            [],
            function ( $registrar ) use ( &$added ) {
                foreach ( [ 'acme', 'other' ] as $id ) {
                    $module = $registrar->module(
                        [
                            'id'    => $id,
                            'label' => ucfirst( $id ),
                        ]
                    );
                    foreach ( [
                        'customer' => 'string',
                        'amount'   => 'acme' === $id ? 'money' : 'string',
                    ] as $key => $type ) {
                        $added[] = $module->trigger(
                            "{$id}_{$key}",
                            [
                                'label'       => 'T',
                                'description' => 'T.',
                                'provides'    => [ 'customer' ],
                                'facts'       => [
                                    $key => [
                                        'label' => 'F',
                                        'type'  => $type,
                                    ],
                                ],
                            ]
                        );
                    }
                }
            }
        );

        $this->assertSame( [ false, true, false, true ], $added, 'one plugin\'s fact type never removes another\'s trigger' );
        $this->assertSame( [], array_filter( array_column( Diagnostics::recent( 24 ), 'message' ), fn( string $message ) => false !== strpos( $message, 'amount' ) ) );
    }
}
