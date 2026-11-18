<?php
namespace YayMatik\Tests\Unit\Core\Definition;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Definition\GraphSchema;
use YayMatik\Workflow\Core\Definition\Messages;
use YayMatik\Workflow\Core\Definition\ProCatalog;
use YayMatik\Workflow\Core\Definition\ProUsage;
use YayMatik\Workflow\Core\Definition\Validator;

require_once dirname( __DIR__, 3 ) . '/Support/woocommerce-stubs.php';

/** What needs YayMatik Pro: nothing with Pro, the Pro parts in the free edition, which keeps them on save. */
final class ProUsageTest extends TestCase {

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

    /**
     * @param array $rules
     */
    private static function condition( array $rules ): array {
        return self::node(
            'c',
            'condition',
            'condition',
            [
                'match' => 'all',
                'rules' => $rules,
            ]
        );
    }

    /**
     * @param string $field
     * @param string $value
     * @param string $type
     */
    private static function rule( string $field, string $value = '1', string $type = 'value' ): array {
        return [
            'field'     => $field,
            'operator'  => 'greater_than',
            'value'     => $value,
            'valueType' => $type,
        ];
    }

    /**
     * Trigger → condition → note.
     *
     * @param array $condition
     * @param array $note
     */
    private static function graph( array $condition, array $note = [ 'note' => 'Hi' ] ): array {
        return [
            'nodes' => [ self::node( 't', 'trigger', 'order_created' ), $condition, self::node( 'n', 'action', 'add_order_note', $note ) ],
            'edges' => [
                [
                    'id'     => 't-c',
                    'source' => 't',
                    'target' => 'c',
                ],
                [
                    'id'           => 'c-n',
                    'source'       => 'c',
                    'target'       => 'n',
                    'sourceHandle' => 'then',
                ],
            ],
        ];
    }

    public function test_nothing_is_missing_with_pro_and_pros_parts_without_it(): void {
        $missing = ProCatalog::missing();

        $this->assertSame( YAYMATIK_TEST_LITE ? array_keys( ProCatalog::STEPS ) : [], $missing['steps'] );
        $this->assertSame( YAYMATIK_TEST_LITE ? ProCatalog::FIELDS : [], $missing['fields'] );
        $this->assertSame( YAYMATIK_TEST_LITE ? ProCatalog::FEATURES : [], $missing['features'] );
    }

    public function test_each_pro_part_is_named_by_node(): void {
        $graph = [
            'nodes' => [
                self::node( 't', 'trigger', 'customer_inactive', [ 'days' => 90 ] ),
                self::condition(
                    [
                        self::rule( 'customer.total_spent' ),
                        [
                            'match' => 'any',
                            'rules' => [ self::rule( 'order.total', 'order.subtotal', 'variable' ) ],
                        ],
                    ]
                ),
                self::node( 'n', 'action', 'add_order_note', [ 'note' => 'Spent {{ order.customer.total_spent | default: "0" }}' ] ),
                self::node( 'free', 'action', 'add_order_note', [ 'note' => 'Hi {{ customer.first_name }}' ] ),
            ],
        ];

        $this->assertSame( YAYMATIK_TEST_LITE ? [ 't', 'c', 'n' ] : [], ProUsage::of( $graph ), 'a Pro step, a condition using Pro (field, group, variable), a Pro merge tag' );
    }

    public function test_the_free_edition_blocks_activate_with_needs_pro_and_nothing_else_on_that_step(): void {
        $graph = GraphSchema::prepare( self::graph( self::condition( [ self::rule( 'order.total' ) ] ), [ 'note' => 'Spent {{ customer.total_spent }}' ] ) );

        $issues = Validator::errors(
            [
                'name'  => 'Spend',
                'graph' => $graph,
            ]
        );

        $this->assertSame(
            YAYMATIK_TEST_LITE ? [
                [
                    'code'    => 'needs_pro',
                    'message' => Messages::get( 'needsPro' ),
                    'nodeId'  => 'n',
                ],
            ] : [],
            $issues
        );
    }

    public function test_the_free_edition_saves_a_condition_needing_pro_as_it_was_stored(): void {
        $group  = self::condition(
            [
                self::rule( 'order.total' ),
                [
                    'match' => 'any',
                    'rules' => [ self::rule( 'order.total', 'order.subtotal', 'variable' ) ],
                ],
            ]
        );
        $stored = GraphSchema::prepare( self::graph( $group ), [ $group ] );

        $this->assertSame( $group['data']['config'], $stored['nodes'][1]['data']['config'], 'kept as written, groups and all' );
        $this->assertSame( YAYMATIK_TEST_LITE, ProUsage::node( $stored['nodes'][1] ) );
    }

    public function test_a_pro_merge_tag_typed_in_the_free_edition_is_saved_as_typed(): void {
        $note   = self::node( 'n', 'action', 'add_order_note', [ 'note' => 'Hi' ] );
        $typed  = self::node( 'n', 'action', 'add_order_note', [ 'note' => 'Spent {{ customer.total_spent }}' ] );
        $graph  = self::graph( self::condition( [ self::rule( 'order.total' ) ] ) );
        $before = $graph;

        $graph['nodes'][2] = $typed;
        $saved             = GraphSchema::prepare( $graph, [ $note ] );

        $this->assertSame( 'Spent {{ customer.total_spent }}', $saved['nodes'][2]['data']['config']['note'], 'the edit is kept, not the stored note' );
        $this->assertNotSame( $before, $graph );
    }
}
