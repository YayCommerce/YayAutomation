<?php
namespace YayMatik\Tests\Unit\Definition;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Definition\GraphSchema;
use YayMatik\Workflow\Definition\Validator;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/**
 * Characterization of graph shape checks on save (v0.1 `prepare_graph()`, now `GraphSchema`): each
 * malformed graph keeps its 400 and exact message; a valid graph keeps its normalized form.
 */
final class GraphShapeTest extends TestCase {

    private const BAD_GRAPH = 'Invalid workflow graph.';
    private const BAD_ID    = 'Invalid or duplicate step id.';
    private const BAD_KIND  = 'Unknown step type.';
    private const TRIGGERS  = 'A workflow needs exactly one trigger.';
    private const BAD_EDGE  = 'Invalid connection between steps.';

    /**
     * @param string      $id    Node id.
     * @param string      $kind  Kind.
     * @param string|null $block Step id.
     * @param array       $config Config.
     */
    private static function node( string $id, string $kind, ?string $block, array $config = [] ): array {
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

    /**
     * @param string      $id     Edge id.
     * @param string      $source Source node.
     * @param string      $target Target node.
     * @param string|null $handle Branch.
     */
    private static function edge( string $id, string $source, string $target, ?string $handle = null ): array {
        return array_filter(
            [
                'id'           => $id,
                'source'       => $source,
                'target'       => $target,
                'sourceHandle' => $handle,
            ],
            fn( $value ) => null !== $value
        );
    }

    /** Trigger → condition → (then) action a, (otherwise) action b. */
    private static function valid(): array {
        return [
            'nodes' => [
                self::node( 't', 'trigger', 'order_created' ),
                self::node( 'c', 'condition', 'condition' ),
                self::node( 'a', 'action', 'add_order_note', [ 'note' => 'Hi' ] ),
                self::node( 'b', 'action', null ),
            ],
            'edges' => [
                self::edge( 'e1', 't', 'c' ),
                self::edge( 'e2', 'c', 'a', 'then' ),
                self::edge( 'e3', 'c', 'b', 'otherwise' ),
            ],
        ];
    }

    /**
     * The valid graph with one value replaced.
     *
     * @param array $path  Keys down to the value, e.g. [ 'nodes', 3, 'id' ].
     * @param mixed $value New value.
     */
    private static function with( array $path, $value ): array {
        $graph = self::valid();
        $ref   = &$graph;
        foreach ( $path as $key ) {
            $ref = &$ref[ $key ];
        }
        $ref = $value;
        return $graph;
    }

    /**
     * The valid graph with one more node or edge.
     *
     * @param string $key   'nodes' or 'edges'.
     * @param mixed  $entry Node or edge.
     */
    private static function plus( string $key, $entry ): array {
        $graph           = self::valid();
        $graph[ $key ][] = $entry;
        return $graph;
    }

    public function malformed(): array {
        return [
            'not an array'             => [ 'graph', self::BAD_GRAPH ],
            'no edges key'             => [ [ 'nodes' => [] ], self::BAD_GRAPH ],
            'nodes not a list'         => [
                [
                    'nodes' => 'x',
                    'edges' => [],
                ],
                self::BAD_GRAPH,
            ],
            'duplicate id'             => [ self::with( [ 'nodes', 3, 'id' ], 'a' ), self::BAD_ID ],
            'bad id characters'        => [ self::with( [ 'nodes', 3, 'id' ], 'b b' ), self::BAD_ID ],
            'node not an array'        => [ self::plus( 'nodes', 'x' ), self::BAD_ID ],
            'unknown kind'             => [ self::with( [ 'nodes', 3, 'kind' ], 'loop' ), self::BAD_KIND, 'b' ],
            'unknown step'             => [ self::with( [ 'nodes', 2, 'data', 'block' ], 'nope' ), self::BAD_KIND, 'a' ],
            'step of another kind'     => [ self::with( [ 'nodes', 2, 'data', 'block' ], 'condition' ), self::BAD_KIND, 'a' ],
            'condition unchosen'       => [ self::with( [ 'nodes', 1, 'data', 'block' ], null ), self::BAD_KIND, 'c' ],
            'two triggers'             => [ self::plus( 'nodes', self::node( 't2', 'trigger', null ) ), self::TRIGGERS ],
            'no trigger'               => [ self::with( [ 'nodes', 0, 'kind' ], 'action' ), self::BAD_KIND, 't' ],
            'no trigger at all'        => [
                [
                    'nodes' => array_slice( self::valid()['nodes'], 1 ),
                    'edges' => [],
                ],
                self::TRIGGERS,
            ],
            'wrong branch handle'      => [ self::with( [ 'edges', 1, 'sourceHandle' ], 'yes' ), self::BAD_EDGE ],
            'condition no handle'      => [ self::with( [ 'edges', 1 ], self::edge( 'e2', 'c', 'a' ) ), self::BAD_EDGE ],
            'action with a handle'     => [ self::with( [ 'edges', 0, 'sourceHandle' ], 'then' ), self::BAD_EDGE ],
            'two parents'              => [ self::plus( 'edges', self::edge( 'e4', 't', 'a' ) ), self::BAD_EDGE ],
            'two children, one handle' => [ self::with( [ 'edges', 2, 'sourceHandle' ], 'then' ), self::BAD_EDGE ],
            'edge into the trigger'    => [ self::plus( 'edges', self::edge( 'e4', 'a', 't' ) ), self::BAD_EDGE ],
            'edge to itself'           => [ self::plus( 'edges', self::edge( 'e4', 'a', 'a' ) ), self::BAD_EDGE ],
            'edge to unknown step'     => [ self::plus( 'edges', self::edge( 'e4', 'a', 'zz' ) ), self::BAD_EDGE ],
            'duplicate edge id'        => [ self::with( [ 'edges', 2, 'id' ], 'e1' ), self::BAD_EDGE ],
            'edge not an array'        => [ self::plus( 'edges', 'x' ), self::BAD_EDGE ],
        ];
    }

    /**
     * @dataProvider malformed
     *
     * @param mixed       $graph   Raw graph.
     * @param string      $message Exact message.
     * @param string|null $node_id Node the error names.
     */
    public function test_malformed_graph_is_a_400( $graph, string $message, ?string $node_id = null ): void {
        $result = Validator::prepare_changes( [ 'graph' => $graph ] );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'yaymatik_invalid_workflow', $result->get_error_code() );
        $this->assertSame( $message, $result->get_error_message() );
        $expected = [ 'status' => 400 ];
        if ( null !== $node_id ) {
            $expected['nodeId'] = $node_id;
        }
        $this->assertSame( $expected, $result->get_error_data() );
    }

    public function test_valid_graph_is_normalized(): void {
        $graph                               = self::valid();
        $graph['nodes'][0]['position']       = [
            'x' => '12',
            'y' => 'junk',
        ];
        $graph['nodes'][0]['extra']          = 'dropped';
        $graph['edges'][0]['animated']       = true;
        $graph['nodes'][2]['data']['config'] = [
            'type' => 'customer',
            'note' => " Hi\r\nthere ",
        ];

        $prepared = Validator::prepare_changes( [ 'graph' => $graph ] )['graph'];

        // schemaVersion is the one addition to the v0.1 shape: always first.
        $this->assertSame( [ 'schemaVersion', 'nodes', 'edges' ], array_keys( $prepared ) );
        $this->assertSame( 1, $prepared['schemaVersion'] );
        $this->assertSame( [ 'id', 'kind', 'position', 'data' ], array_keys( $prepared['nodes'][0] ) );
        $this->assertSame(
            [
                'x' => 12.0,
                'y' => 0.0,
            ],
            $prepared['nodes'][0]['position']
        );
        $this->assertSame(
            [
                'note' => "Hi\nthere",
                'type' => 'customer',
            ],
            $prepared['nodes'][2]['data']['config']
        );
        $this->assertSame(
            [
                'block'  => null,
                'config' => [],
            ],
            $prepared['nodes'][3]['data']
        );
        $this->assertSame(
            [
                [
                    'id'     => 'e1',
                    'source' => 't',
                    'target' => 'c',
                ],
                [
                    'id'           => 'e2',
                    'source'       => 'c',
                    'target'       => 'a',
                    'sourceHandle' => 'then',
                ],
                [
                    'id'           => 'e3',
                    'source'       => 'c',
                    'target'       => 'b',
                    'sourceHandle' => 'otherwise',
                ],
            ],
            $prepared['edges']
        );
    }

    public function test_same_graph_ignores_what_normalizing_drops(): void {
        $noisy                         = self::valid();
        $noisy['nodes'][0]['selected'] = true;
        $other                         = self::with( [ 'nodes', 2, 'data', 'config', 'note' ], 'Bye' );

        $this->assertTrue( GraphSchema::same( self::valid(), $noisy ) );
        $this->assertFalse( GraphSchema::same( self::valid(), $other ) );
        $this->assertTrue( GraphSchema::same( null, null ) );
        $this->assertFalse( GraphSchema::same( self::valid(), null ) );
    }

    public function test_new_workflow_without_graph_is_one_unchosen_trigger(): void {
        $new = Validator::prepare_new( [] );

        $this->assertSame( 'New workflow', $new['name'] );
        $this->assertSame( [ 'trigger' ], array_column( $new['graph']['nodes'], 'id' ) );
        $this->assertNull( $new['graph']['nodes'][0]['data']['block'] );
        $this->assertSame( [], $new['graph']['edges'] );
    }

    public function test_missing_schema_version_reads_as_1(): void {
        $this->assertSame( 1, GraphSchema::version( self::valid() ) );
        $this->assertSame( 1, GraphSchema::version( [ 'schemaVersion' => 1 ] ) );
        $this->assertNull( GraphSchema::version( [ 'schemaVersion' => '1' ] ) );
        $this->assertNull( GraphSchema::version( [ 'schemaVersion' => 0 ] ) );
    }

    public function test_an_old_graph_without_version_equals_the_same_graph_saved_today(): void {
        $saved = Validator::prepare_changes( [ 'graph' => self::valid() ] )['graph'];

        $this->assertArrayNotHasKey( 'schemaVersion', self::valid() );
        $this->assertTrue( GraphSchema::same( self::valid(), $saved ), 'no false "Draft changes"' );
        $this->assertSame( $saved, GraphSchema::prepare( $saved ), 'preparing twice changes nothing' );
    }

    public function test_a_newer_schema_version_is_a_400(): void {
        $graph                  = self::valid();
        $graph['schemaVersion'] = 2;
        $result                 = Validator::prepare_changes( [ 'graph' => $graph ] );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( [ 'status' => 400 ], $result->get_error_data() );
        $this->assertSame( 'This workflow was saved by a newer version of YayMatik. Update the plugin to edit it.', $result->get_error_message() );

        $graph['schemaVersion'] = 'one';
        $this->assertSame( 'Invalid workflow graph.', Validator::prepare_changes( [ 'graph' => $graph ] )->get_error_message() );
    }
}
