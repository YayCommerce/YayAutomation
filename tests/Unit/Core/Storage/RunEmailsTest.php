<?php
namespace YayMatik\Tests\Unit\Core\Storage;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeWpdb;
use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;

/** The privacy index: what each write adds to `run_emails`, matching what the old scan found. */
final class RunEmailsTest extends TestCase {

    /** @var FakeWpdb */
    private $db;

    /** @var string */
    private $log;

    /** @var string|false */
    private $old_log;

    protected function setUp(): void {
        $this->db = new FakeWpdb();
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
        $GLOBALS['wpdb'] = $this->db;
        $this->log       = (string) tempnam( sys_get_temp_dir(), 'yaymatik-log' );
        $this->old_log   = ini_set( 'error_log', $this->log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Logger writes to the PHP error log without WooCommerce.
    }

    protected function tearDown(): void {
        ini_set( 'error_log', (string) $this->old_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
        unlink( $this->log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    /**
     * Index values one write adds, read back from its INSERT.
     *
     * @param string[] $json
     * @param string[] $text
     * @return string[]
     */
    private function indexed( array $json, array $text = [] ): array {
        $this->db->calls = [];
        RunStore::index_emails( $json, $text, 'SELECT %d AS run_id', [ 1 ] );
        $sql = $this->index_sql();
        if ( '' === $sql ) {
            return [];
        }
        preg_match_all( "/SELECT '((?:[^'\\\\]|\\\\.)*)' AS email/", $sql, $found );
        $emails = array_map( 'stripslashes', $found[1] );
        sort( $emails );
        return $emails;
    }

    private function index_sql(): string {
        foreach ( $this->db->calls as $call ) {
            if ( 'query' === $call[0] && 0 === strpos( $call[1], 'INSERT IGNORE INTO `wp_yaymatik_run_emails`' ) ) {
                return $call[1];
            }
        }
        return '';
    }

    public function test_json_counts_only_a_whole_string_that_is_an_email_or_a_claim_key(): void {
        $key = 'email:' . hash( 'sha256', 'bob@x.test' );

        $this->assertSame( [ 'bob@x.test' ], $this->indexed( [ '{"to":"Bob@X.test"}' ] ) );
        $this->assertSame( [ 'bob@x.test' ], $this->indexed( [ '{"bob@x.test":1}' ] ) );
        $this->assertSame( [ 'a@x.test', 'b@x.test' ], $this->indexed( [ '{"to":["a@x.test",{"cc":"b@x.test"}]}', '{"n":3}' ] ) );
        $this->assertSame( [ $key ], $this->indexed( [ wp_json_encode( [ 'claim_key' => $key ] ) ] ) );
        $this->assertSame( [], $this->indexed( [ '{"to":" bob@x.test"}', '{"note":"Hi bob@x.test"}', '{"k":"email:abc"}', 'not json' ] ) );
    }

    public function test_text_finds_what_the_old_regexp_found(): void {
        $this->assertSame( [ 'bob@x.test' ], $this->indexed( [], [ 'Sent to bob@x.test.' ] ) );
        $this->assertSame( [ 'bob@x.test' ], $this->indexed( [], [ 'Sent to <BOB@X.TEST>, twice: bob@x.test..' ] ) );
        $this->assertSame( [ 'bob@x.test.au' ], $this->indexed( [], [ 'bob@x.test.au' ] ) );
        $this->assertSame( [ 'bob@x.test-x' ], $this->indexed( [], [ 'bob@x.test-x' ] ) );
        $this->assertSame( [ 'xbob@x.test' ], $this->indexed( [], [ 'xbob@x.test' ] ) );
        // The old REGEXP for `neil@x.test` matched here too: an apostrophe isn't in its look-behind class.
        $this->assertSame( [ 'neil@x.test', "o'neil@x.test" ], $this->indexed( [], [ "Mail for o'neil@x.test failed." ] ) );
        $this->assertSame( [ 'a@x.test', 'b@y.test' ], $this->indexed( [], [ 'a@x.test, b@y.test' ] ) );
        $this->assertSame( [], $this->indexed( [], [ 'No address @ here', '' ] ) );
    }

    public function test_values_are_cut_to_190_characters(): void {
        $long = str_repeat( 'a', 60 ) . '@' . str_repeat( 'b', 60 ) . '.' . str_repeat( 'c', 60 ) . '.' . str_repeat( 'd', 30 ) . '.test';
        $this->assertSame( [ substr( $long, 0, 190 ) ], $this->indexed( [ wp_json_encode( [ 'to' => $long ] ) ] ) );
    }

    public function test_every_write_indexes_its_run_only_when_it_holds_an_email(): void {
        $writes = [
            'create'         => [
                fn() => RunStore::create(
                    [
                        'workflow_id' => 1,
                        'version_id'  => 2,
                        'scope'       => [ 'to' => 'a@x.test' ],
                    ]
                ),
                'SELECT 1 AS run_id',
            ],
            'save'           => [ fn() => RunStore::save( 5, [ 'error' => 'Bounced: a@x.test' ] ), 'SELECT id AS run_id FROM `wp_yaymatik_runs` WHERE id = 5' ],
            'cancel_waiting' => [ fn() => RunStore::cancel_waiting( [ 4, 5 ], 'Erased a@x.test' ), "SELECT id AS run_id FROM `wp_yaymatik_runs` WHERE id IN (4,5) AND status = 'cancelled' AND error = 'Erased a@x.test'" ],
            'start'          => [ fn() => RunStepStore::start( 7, 'n1', [ 'to' => 'a@x.test' ] ), 'SELECT id AS run_id FROM `wp_yaymatik_runs` WHERE id = 7' ],
            'finish'         => [ fn() => RunStepStore::finish( 3, RunStepStore::STATUS_FAILED, [], 'Bounced: a@x.test' ), 'SELECT run_id FROM `wp_yaymatik_run_steps` WHERE id = 3' ],
            'close_open'     => [ fn() => RunStepStore::close_open( 7, RunStepStore::STATUS_CANCELLED, 'Erased a@x.test' ), 'SELECT id AS run_id FROM `wp_yaymatik_runs` WHERE id = 7' ],
            'steps cancel'   => [ fn() => RunStepStore::cancel_waiting( [ 4 ], 'Erased a@x.test' ), "SELECT DISTINCT run_id FROM `wp_yaymatik_run_steps` WHERE run_id IN (4) AND status = 'cancelled' AND error = 'Erased a@x.test'" ],
        ];
        foreach ( $writes as $name => list( $write, $runs ) ) {
            $this->db         = new FakeWpdb();
            $this->db->result = 1;
            $GLOBALS['wpdb']  = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
            $write();
            $this->assertSame( "INSERT IGNORE INTO `wp_yaymatik_run_emails` (run_id, email) SELECT r.run_id, e.email FROM ({$runs}) r CROSS JOIN (SELECT 'a@x.test' AS email) e", $this->index_sql(), $name );
        }

        $this->db->calls = [];
        RunStore::create(
            [
                'workflow_id' => 1,
                'version_id'  => 2,
                'scope'       => [ 'trigger' => [ 'order' => 'order:12' ] ],
            ]
        );
        RunStore::save( 5, [ 'status' => RunStore::STATUS_COMPLETED ] );
        RunStepStore::start( 7, 'n1', [ 'note' => 'Hi' ] );
        RunStepStore::finish( 3, RunStepStore::STATUS_COMPLETED );
        RunStepStore::close_open( 7, RunStepStore::STATUS_CANCELLED, 'Off.' );
        $this->assertSame( '', $this->index_sql() );
    }

    public function test_cancel_writes_index_nothing_when_no_row_changed(): void {
        $this->db->result = 0;
        RunStore::cancel_waiting( [ 4 ], 'Erased a@x.test' );
        RunStepStore::close_open( 7, RunStepStore::STATUS_CANCELLED, 'Erased a@x.test' );
        RunStepStore::cancel_waiting( [ 4 ], 'Erased a@x.test' );
        $this->assertSame( '', $this->index_sql() );
    }

    public function test_an_index_failure_is_logged_and_the_write_stands(): void {
        $this->db->result = false;
        $this->assertTrue( RunStepStore::finish( 3, RunStepStore::STATUS_COMPLETED, [ 'to' => 'a@x.test' ] ) );
        $this->assertStringContainsString( "Could not index a run's emails", (string) file_get_contents( $this->log ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
        $GLOBALS['wpdb'] = new class() {
            /** @var string */
            public $prefix = 'wp_';

            /** @var string */
            public $last_error = '';

            /** @var int */
            public $insert_id = 0;

            public function prepare( $query ) {
                return $query;
            }

            public function insert() {
                $this->insert_id = 9;
                return 1;
            }

            public function update() {
                return 1;
            }

            public function query() {
                throw new \RuntimeException( 'Gone away' );
            }
        };
        $this->assertSame( 9, RunStepStore::start( 7, 'n1', [ 'to' => 'a@x.test' ] ) );
        $this->assertStringContainsString( "Could not index a run's emails: Gone away", (string) file_get_contents( $this->log ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    }

    public function test_lookups_join_the_index_and_the_subjects(): void {
        $key   = 'email:' . hash( 'sha256', 'bob@x.test' );
        $about = "(SELECT run_id AS id FROM `wp_yaymatik_run_emails` WHERE email IN ('bob@x.test', '{$key}') UNION SELECT id FROM `wp_yaymatik_runs` WHERE subject IN ('cart:4','order:9')%s) m";

        $this->db->result = [];
        RunStore::for_export( 'bob@x.test', [ 'cart:4', 'order:9' ], 20, 40 );
        $this->assertSame( 'SELECT r.id, r.status, r.started_at, w.name AS workflow_name FROM `wp_yaymatik_runs` r INNER JOIN ' . sprintf( $about, '' ) . ' ON m.id = r.id LEFT JOIN `wp_yaymatik_workflows` w ON w.id = r.workflow_id ORDER BY r.id LIMIT 20 OFFSET 40', $this->db->last_sql() );

        RunStore::ids_mentioning( 'bob@x.test', [ 'cart:4', 'order:9' ], 20 );
        $this->assertSame( 'SELECT r.id, r.status FROM `wp_yaymatik_runs` r INNER JOIN ' . sprintf( $about, " AND status = 'waiting'" ) . " ON m.id = r.id WHERE r.status NOT IN ('queued','running') ORDER BY r.id LIMIT 20", $this->db->last_sql() );

        $this->db->result = null;
        $this->assertFalse( RunStore::in_progress_mentioning( 'bob@x.test', [] ) );
        $this->assertSame( "SELECT r.id FROM `wp_yaymatik_runs` r INNER JOIN (SELECT run_id AS id FROM `wp_yaymatik_run_emails` WHERE email IN ('bob@x.test', '{$key}')) m ON m.id = r.id WHERE r.status IN ('queued','running') LIMIT 1", $this->db->last_sql() );
    }

    public function test_erase_removes_the_persons_index_rows_except_where_it_failed(): void {
        $key               = 'email:' . hash( 'sha256', 'bob@x.test' );
        $this->db->results = [
            [ '4', '5' ],
            [
                [
                    'id'    => '4',
                    'scope' => '{"to":"bob@x.test"}',
                    'error' => null,
                ],
            ],
            [],
            [ '5' ],
        ];
        // Run 4 scrubbed, run 5's link left by an overwritten value removed: both count.
        $this->assertSame( 2, RunStore::anonymize( [ 4, 5, 6 ], 'bob@x.test' ) );
        $this->assertSame( "DELETE FROM `wp_yaymatik_run_emails` WHERE run_id IN (4,5) AND email IN ('bob@x.test','{$key}')", $this->db->last_sql() );

        // The scrub of run 4 fails: its rows stay for the next erase.
        $db = new class() {
            /** @var string */
            public $prefix = 'wp_';

            /** @var string */
            public $last_error = '';

            /** @var string[] */
            public $sql = [];

            /** @var array */
            public $results = [];

            public function prepare( $query, ...$args ) {
                return ( new FakeWpdb() )->prepare( $query, ...$args );
            }

            public function get_col( $sql ) {
                return false !== strpos( $sql, 'run_emails' ) ? [] : [ '4', '5' ];
            }

            public function get_results( $sql ) {
                return false !== strpos( $sql, 'wp_yaymatik_runs' ) ? [
                    [
                        'id'    => '4',
                        'scope' => '{"to":"bob@x.test"}',
                        'error' => null,
                    ],
                ] : [];
            }

            public function update() {
                return false;
            }

            public function query( $sql ) {
                $this->sql[] = $sql;
                return 1;
            }
        };
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
        $GLOBALS['wpdb'] = $db;
        RunStore::anonymize( [ 4, 5 ], 'bob@x.test' );
        $this->assertSame( [ "DELETE FROM `wp_yaymatik_run_emails` WHERE run_id IN (5) AND email IN ('bob@x.test','{$key}')" ], $db->sql );

        // A read that fails leaves every link.
        $db->sql        = [];
        $db->last_error = 'Lock wait timeout';
        RunStore::anonymize( [ 4, 5 ], 'bob@x.test' );
        $this->assertSame( [], $db->sql );
    }

    public function test_deleting_runs_removes_their_index_rows_last(): void {
        $this->db->result = 1;
        $this->assertTrue( RunStore::delete_with_steps( [ 4, 5 ] ) );
        $this->assertSame(
            [
                'DELETE FROM `wp_yaymatik_run_steps` WHERE run_id IN (4,5)',
                'DELETE FROM `wp_yaymatik_runs` WHERE id IN (4,5)',
                'DELETE FROM `wp_yaymatik_run_emails` WHERE run_id IN (4,5)',
            ],
            array_column( $this->db->calls, 1 )
        );
    }

    public function test_erase_keeps_the_link_of_an_address_cut_to_190(): void {
        $long              = str_repeat( 'a', 60 ) . '@' . str_repeat( 'b', 140 ) . '.test';
        $key               = 'email:' . hash( 'sha256', $long );
        $this->db->results = [ [ '4' ], [], [], [] ];
        RunStore::anonymize( [ 4 ], $long );
        $this->assertSame( "DELETE FROM `wp_yaymatik_run_emails` WHERE run_id IN (4) AND email IN ('{$key}')", $this->db->last_sql() );
    }

    public function test_a_new_run_first_drops_index_rows_left_for_its_id(): void {
        RunStore::create(
            [
                'workflow_id' => 1,
                'version_id'  => 2,
                'scope'       => [ 'to' => 'a@x.test' ],
            ]
        );
        $queries = array_column( array_filter( $this->db->calls, fn( array $call ) => 'query' === $call[0] ), 1 );
        $this->assertSame( 'DELETE FROM `wp_yaymatik_run_emails` WHERE run_id = 1', $queries[0] );
        $this->assertStringStartsWith( 'INSERT IGNORE INTO `wp_yaymatik_run_emails`', $queries[1] );
    }
}
