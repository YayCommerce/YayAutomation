<?php
namespace YayMatik\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use YayMatik\Api\Api;
use YayMatik\Tests\Support\FakeWpdb;

/** `Api::references()`: the values a plugin's step still uses in any version or draft (docs/extension-api.md §5.6). */
final class ReferencesTest extends TestCase {

    /** @var FakeWpdb */
    private $db;

    protected function setUp(): void {
        global $wpdb;
        $this->db = new FakeWpdb();
        $wpdb     = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the store reads the global.
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = new FakeWpdb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the bootstrap's empty one.
    }

    /**
     * A stored graph with one node per [ step, design ].
     *
     * @param array ...$nodes
     */
    private static function graph( array ...$nodes ): string {
        return (string) wp_json_encode(
            [
                'nodes' => array_map(
                    fn( array $node, int $i ) => [
                        'id'   => "n{$i}",
                        'data' => [
                            'block'  => $node[0],
                            'config' => [ 'design_id' => $node[1] ],
                        ],
                    ],
                    $nodes,
                    array_keys( $nodes )
                ),
            ]
        );
    }

    public function test_distinct_values_of_the_field_in_every_graph_with_the_step(): void {
        $this->db->result = [
            self::graph( [ 'acme_mail', '7' ], [ 'acme_mail', '' ], [ 'other_mail', '9' ] ),
            self::graph( [ 'acme_mail', '7' ], [ 'acme_mail', '12' ] ),
            'not json',
        ];

        $this->assertSame( [ '7', '12' ], Api::references( 'acme_mail', 'design_id' ), 'another step’s value and blanks left out' );
        $this->assertStringContainsString( 'LIKE \'%\\"block\\":\\"acme\\\\_mail\\"%\'', $this->db->last_sql() );
    }
}
