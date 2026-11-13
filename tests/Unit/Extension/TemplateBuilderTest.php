<?php
namespace YayMatik\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Extension\TemplateBuilder;

/** A template's chain of steps becomes a graph laid out like the editor's (docs/extension-api.md §7). */
final class TemplateBuilderTest extends TestCase {

    /**
     * @param array $steps
     * @return array|string
     */
    private static function graph( array $steps ) {
        return TemplateBuilder::graph( $steps );
    }

    public function test_a_line_steps_down_one_row_at_a_time(): void {
        $graph = self::graph(
            [
                [
                    'id'   => 'trigger',
                    'step' => 'order_paid',
                ],
                [
                    'id'     => 'wait',
                    'step'   => 'wait',
                    'config' => [
                        'delay' => [
                            'value' => 1,
                            'unit'  => 'days',
                        ],
                    ],
                ],
                [
                    'id'   => 'mail',
                    'step' => 'send_email',
                ],
            ]
        );

        $this->assertSame(
            [
                [ 'trigger', 'trigger', 0, 0, 'order_paid', [] ],
                [ 'wait', 'action', 0, 170, 'wait', [ 'delay' => [ 'value' => 1, 'unit' => 'days' ] ] ], // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- a table row.
                [ 'mail', 'action', 0, 340, 'send_email', [] ],
            ],
            array_map( fn( array $node ) => [ $node['id'], $node['kind'], $node['position']['x'], $node['position']['y'], $node['data']['block'], $node['data']['config'] ], $graph['nodes'] )
        );
        $this->assertSame( [ 'e-trigger-wait', 'e-wait-mail' ], array_column( $graph['edges'], 'id' ) );
        $this->assertArrayNotHasKey( 'sourceHandle', $graph['edges'][0] );
    }

    public function test_a_condition_branches_left_for_then_and_right_for_otherwise(): void {
        $graph = self::graph(
            [
                [
                    'id'   => 'trigger',
                    'step' => 'order_paid',
                ],
                [
                    'id'        => 'check',
                    'step'      => 'condition',
                    'then'      => [
                        [
                            'id'   => 'yes',
                            'step' => 'send_email',
                        ],
                        [
                            'id'   => 'after',
                            'step' => 'wait',
                        ],
                    ],
                    'otherwise' => [
                        [
                            'id'   => 'no',
                            'step' => 'wait',
                        ],
                    ],
                ],
            ]
        );

        $at = array_column( array_map( fn( array $node ) => [ $node['id'], [ $node['position']['x'], $node['position']['y'] ] ], $graph['nodes'] ), 1, 0 );
        $this->assertSame(
            [
                'trigger' => [ 0, 0 ],
                'check'   => [ 0, 170 ],
                'yes'     => [ -200, 340 ],
                'after'   => [ -200, 510 ],
                'no'      => [ 200, 340 ],
            ],
            $at
        );
        $this->assertSame(
            [ [ 'check', 'yes', 'then' ], [ 'yes', 'after', null ], [ 'check', 'no', 'otherwise' ] ],
            array_map( fn( array $edge ) => [ $edge['source'], $edge['target'], $edge['sourceHandle'] ?? null ], array_slice( $graph['edges'], 1 ) )
        );
    }

    public function test_a_chain_that_cant_be_a_graph_says_why(): void {
        $trigger = [
            'id'   => 'trigger',
            'step' => 'order_paid',
        ];
        $cases   = [
            'The first step must be a trigger.' => [
                [
                    'id'   => 'w',
                    'step' => 'wait',
                ],
            ],
            'Step "t2": a trigger can only be the first step.' => [
                $trigger,
                [
                    'id'   => 't2',
                    'step' => 'order_paid',
                ],
            ],
            'Step "x": unknown step "nope".'    => [
                $trigger,
                [
                    'id'   => 'x',
                    'step' => 'nope',
                ],
            ],
            'Each step needs its own "id" (lowercase letters, digits, _ or -); "trigger" isn\'t one.' => [
                $trigger,
                $trigger,
            ],
            'Step "c": a condition ends its list; put the steps after it under "then" / "otherwise".' => [
                $trigger,
                [
                    'id'   => 'c',
                    'step' => 'condition',
                ],
                [
                    'id'   => 'w',
                    'step' => 'wait',
                ],
            ],
            'Step "w": only a condition has "then" / "otherwise".' => [
                $trigger,
                [
                    'id'   => 'w',
                    'step' => 'wait',
                    'then' => [],
                ],
            ],
        ];
        foreach ( $cases as $reason => $steps ) {
            $this->assertSame( $reason, self::graph( $steps ) );
        }
    }
}
