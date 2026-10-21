<?php
namespace YayMatik\Tests\Unit\Core\Storage;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeClock;
use YayMatik\Tests\Support\FakeCondition;
use YayMatik\Tests\Support\FakeGraphSource;
use YayMatik\Tests\Support\FakeQueue;
use YayMatik\Tests\Support\FakeStep;
use YayMatik\Tests\Support\FakeWpdb;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Runtime\DbRunRepository;
use YayMatik\Workflow\Core\Runtime\Executor;
use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;

/** What the run stores send to the database. The real tables are checked with `./run.sh check-runs`. */
final class RunStepStoreTest extends TestCase {

    /** @var FakeWpdb */
    private $db;

    protected function setUp(): void {
        global $wpdb;
        $this->db = new FakeWpdb();
        $wpdb     = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the store reads the global.
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
    }

    public function test_start_inserts_a_running_row_with_json_input(): void {
        $id = RunStepStore::start( 7, 'n2', [ 'note' => 'Hi {{ order.number }}' ] );

        $this->assertSame( 1, $id );
        list( , $table, $data ) = $this->db->calls[0];
        $this->assertSame( 'wp_yaymatik_run_steps', $table );
        $this->assertSame( 7, $data['run_id'] );
        $this->assertSame( 'n2', $data['step_id'] );
        $this->assertSame( 'running', $data['status'] );
        $this->assertSame( '{"note":"Hi {{ order.number }}"}', $data['input'] );
        $this->assertNotEmpty( $data['started_at'] );
    }

    public function test_finish_sets_finished_at_except_while_waiting(): void {
        $this->assertTrue( RunStepStore::finish( 3, RunStepStore::STATUS_COMPLETED, [ 'to' => 'a@x.com' ] ) );
        $this->assertTrue( RunStepStore::finish( 4, RunStepStore::STATUS_WAITING ) );
        $this->assertFalse( RunStepStore::finish( 5, 'nope' ) );

        list( , , $done, $where ) = $this->db->calls[0];
        $this->assertSame( [ 'id' => 3 ], $where );
        $this->assertSame( 'completed', $done['status'] );
        $this->assertSame( '{"to":"a@x.com"}', $done['output'] );
        $this->assertNull( $done['error'] );
        $this->assertArrayHasKey( 'finished_at', $done );
        $this->assertArrayNotHasKey( 'finished_at', $this->db->calls[1][2] );
        $this->assertCount( 2, $this->db->calls );
    }

    public function test_for_run_decodes_rows_in_order(): void {
        $this->db->result = [
            [
                'id'          => '1',
                'run_id'      => '7',
                'step_id'     => 'n2',
                'iteration'   => '0',
                'status'      => 'completed',
                'input'       => '{"note":"x"}',
                'output'      => null,
                'error'       => null,
                'attempt'     => '1',
                'started_at'  => '2026-10-12 10:00:00',
                'finished_at' => '2026-10-12 10:00:01',
            ],
        ];

        $rows = RunStepStore::for_run( 7 );

        $this->assertSame( 'SELECT * FROM wp_yaymatik_run_steps WHERE run_id = 7 ORDER BY id ASC', $this->db->last_sql() );
        $this->assertSame( 1, $rows[0]['id'] );
        $this->assertSame( [ 'note' => 'x' ], $rows[0]['input'] );
        $this->assertSame( [], $rows[0]['output'] );
    }

    public function test_recent_counts_counts_all_and_chained_runs_of_one_workflow_since_the_cutoff(): void {
        $this->db->result = [
            'total'   => '150',
            'chained' => '3',
        ];

        $this->assertSame(
            [
                'all'     => 150,
                'chained' => 3,
            ],
            RunStore::recent_counts( 5, 60 )
        );
        $this->assertMatchesRegularExpression(
            "/^SELECT COUNT\(\*\) AS total, COALESCE\(SUM\(depth > 0\), 0\) AS chained FROM wp_yaymatik_runs WHERE workflow_id = 5 AND started_at >= '\d{4}-\d\d-\d\d \d\d:\d\d:\d\d'$/",
            $this->db->last_sql()
        );
    }

    /**
     * A stored row as `$wpdb` returns it: every column a string, JSON as text.
     *
     * @param string      $step_id Step id.
     * @param string|null $output  Output JSON.
     */
    private static function db_row( string $step_id, ?string $output ): array {
        return [
            'id'          => '1',
            'run_id'      => '7',
            'step_id'     => $step_id,
            'iteration'   => '0',
            'status'      => 'completed',
            'input'       => '{"rules":[]}',
            'output'      => $output,
            'error'       => null,
            'attempt'     => '1',
            'started_at'  => '2026-10-12 10:00:00',
            'finished_at' => '2026-10-12 10:00:01',
        ];
    }

    public function test_json_columns_decode_to_arrays_and_anything_else_to_empty(): void {
        $this->db->result = [
            self::db_row( 'a', '{"result":"yes"}' ),
            self::db_row( 'b', null ),
            self::db_row( 'c', '"text"' ),
            self::db_row( 'd', '{broken' ),
        ];

        $rows = ( new DbRunRepository() )->steps( 7 );

        $this->assertSame( [ 'rules' => [] ], $rows[0]['input'] );
        $this->assertSame( [ 'result' => 'yes' ], $rows[0]['output'] );
        $this->assertSame( [ [], [], [] ], array_column( array_slice( $rows, 1 ), 'output' ) );
    }

    public function test_resumed_condition_row_from_the_table_follows_its_stored_branch(): void {
        // Every read returns the condition's finished row: a crash came after it, before the cursor save.
        $this->db->result  = [ self::db_row( 'c', '{"result":"yes"}' ) ];
        $condition         = new FakeCondition();
        $then              = new FakeStep( 'act' );
        $graphs            = new FakeGraphSource();
        $graphs->graphs[3] = [
            'nodes' => [
                [
                    'id'   => 't',
                    'kind' => 'trigger',
                    'data' => [
                        'block'  => 'order_created',
                        'config' => [],
                    ],
                ],
                [
                    'id'   => 'c',
                    'kind' => 'condition',
                    'data' => [
                        'block'  => 'fake_condition',
                        'config' => [],
                    ],
                ],
                [
                    'id'   => 'yes',
                    'kind' => 'action',
                    'data' => [
                        'block'  => 'act',
                        'config' => [],
                    ],
                ],
            ],
            'edges' => [
                [
                    'source'       => 't',
                    'target'       => 'c',
                    'sourceHandle' => null,
                ],
                [
                    'source'       => 'c',
                    'target'       => 'yes',
                    'sourceHandle' => 'then',
                ],
            ],
        ];
        $steps             = [
            'fake_condition' => $condition,
            'act'            => $then,
        ];
        $executor          = new Executor( new DbRunRepository(), $graphs, new FakeQueue(), new FakeClock(), fn( string $id ) => $steps[ $id ] ?? null, fn() => new RefResolver( [] ) );

        $status = $executor->run(
            [
                'id'         => 7,
                'version_id' => 3,
                'position'   => null,
                'scope'      => [],
            ]
        );

        $this->assertSame( 'completed', $status );
        $this->assertSame( 0, $condition->runs );
        $this->assertSame( 1, $then->runs );
        $inserts = array_values( array_filter( $this->db->calls, fn( array $call ) => 'insert' === $call[0] ) );
        $this->assertSame( [ 'yes' ], array_map( fn( array $call ) => $call[2]['step_id'], $inserts ) );
        $last = end( $this->db->calls );
        $this->assertSame( [ 'result' => 'yes' ], json_decode( $last[2]['scope'], true )['steps']['c'] );
    }

    public function test_claim_takes_a_waiting_run_only_after_its_time(): void {
        $this->db->result = 1;

        $this->assertTrue( RunStore::claim( 7 ) );
        $this->assertCount( 1, $this->db->calls );
        $this->assertMatchesRegularExpression(
            "/^UPDATE wp_yaymatik_runs SET status = 'running' WHERE id = 7 AND status IN \('queued','waiting'\) AND \( status <> 'waiting' OR wait_until IS NULL OR wait_until <= '[0-9: -]+' \)$/",
            $this->db->last_sql()
        );
    }

    public function test_claim_from_running_hands_the_run_back_as_queued_first(): void {
        $this->db->result = 1;

        $this->assertTrue( RunStore::claim( 7, [ 'queued', 'waiting', 'running' ] ) );
        $this->assertSame( "UPDATE wp_yaymatik_runs SET status = 'queued' WHERE id = 7 AND status = 'running'", $this->db->calls[0][1] );
        $this->assertStringContainsString( "status IN ('queued','waiting')", $this->db->calls[1][1] );
    }
}
