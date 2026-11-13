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
                        'run'         => fn( array $config, $run ) => $run->done(),
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
        foreach ( self::action( [ 'fields' => $fields ] )->fields() as $field ) {
            $shapes[ $field->key() ] = $field->to_array();
        }
        return $shapes;
    }

    private static function last_diagnostic(): string {
        return Diagnostics::recent( 24 )[0]['message'] ?? '';
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
                    'required' => 'Pick a role for %s.',
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
        $this->assertSame( [ 'select', true, 'Pick a role for %s.', 'silver' ], [ $fields['role']['type'], $fields['role']['required'], $fields['role']['requiredMessage'], $fields['role']['default'] ] );
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
                'fields' => [
                    'points' => [
                        'type'     => 'number',
                        'label'    => 'Points',
                        'required' => 'Enter the points for %s.',
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
            'Field "role" needs a "type" from: text, textarea, html, number, select, multiselect, toggle, emails, notice, number_unit.' => [ 'fields' => [ 'role' => [ 'label' => 'Role' ] ] ],
            'Field "role" needs "options".'            => [
                'fields' => [
                    'role' => [
                        'type'  => 'select',
                        'label' => 'Role',
                    ],
                ],
            ],
            'Field key "Role" must be lowercase letters, digits or underscores.' => [
                'fields' => [
                    'Role' => [
                        'type'  => 'text',
                        'label' => 'Role',
                    ],
                ],
            ],
            'Field "role": "show_if" must be [ field => value ].' => [
                'fields' => [
                    'role' => [
                        'type'    => 'text',
                        'label'   => 'Role',
                        'show_if' => [ 'a', 'b' ],
                    ],
                ],
            ],
            '"run" must be a callable.'                => [ 'run' => 'no_such_function' ],
            '"on_error" must be "stop" or "continue".' => [ 'on_error' => 'retry' ],
            '"category" must be a core category or one this module declared first.' => [ 'category' => 'rewards' ],
            'Unknown data item in "requires": ordr.'   => [ 'requires' => [ 'ordr' ] ],
        ];
        foreach ( $cases as $message => $args ) {
            $GLOBALS['yaymatik_test_options'] = [];
            $this->assertNull( self::action( $args ), $message );
            $this->assertSame( $message, self::last_diagnostic() );
        }
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
        $this->assertSame( '"provides" must list data items a payload gives: customer, order, cart, review.', self::last_diagnostic() );
    }
}
