<?php
namespace YayMatik\Tests\Unit\Definition;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Definition\GraphSchema;
use YayMatik\Workflow\Definition\Validator;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** `Validator::steps_above()`: what Preview walks to give earlier steps' sample outputs. */
final class StepsAboveTest extends TestCase {

    private static function node( string $id, string $kind, string $block ): array {
        return [
            'id'       => $id,
            'kind'     => $kind,
            'position' => [
                'x' => 0,
                'y' => 0,
            ],
            'data'     => [
                'block'  => $block,
                'config' => [],
            ],
        ];
    }

    private static function edge( string $source, string $target, ?string $handle = null ): array {
        return array_filter(
            [
                'id'           => "$source-$target",
                'source'       => $source,
                'target'       => $target,
                'sourceHandle' => $handle,
            ]
        );
    }

    public function test_from_the_trigger_down(): void {
        $graph = GraphSchema::prepare(
            [
                'nodes' => [
                    self::node( 't', 'trigger', 'order_created' ),
                    self::node( 'c', 'condition', 'condition' ),
                    self::node( 'a', 'action', 'add_order_note' ),
                    self::node( 'b', 'action', 'add_order_note' ),
                    self::node( 'other', 'action', 'add_order_note' ),
                ],
                'edges' => [ self::edge( 't', 'c' ), self::edge( 'c', 'a', 'then' ), self::edge( 'a', 'b' ), self::edge( 'c', 'other', 'otherwise' ) ],
            ]
        );

        $this->assertSame( [ 't', 'c', 'a' ], Validator::steps_above( $graph, 'b' ) );
        $this->assertSame( [ 't', 'c' ], Validator::steps_above( $graph, 'other' ), 'not the other branch' );
        $this->assertSame( [], Validator::steps_above( $graph, 't' ) );
    }
}
