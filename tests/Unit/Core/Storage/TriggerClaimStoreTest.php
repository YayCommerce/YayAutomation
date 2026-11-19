<?php
namespace YayMatik\Tests\Unit\Core\Storage;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeWpdb;
use YayMatik\Workflow\Core\Storage\TriggerClaimStore;

/** Claims: the insert wins once; with a cooldown, an update of an old enough claim wins once more. */
final class TriggerClaimStoreTest extends TestCase {

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

    public function test_first_claim_is_an_insert_and_wins(): void {
        $this->db->results = [ 1 ];

        $this->assertTrue( TriggerClaimStore::claim( 3, 'user:7', 600 ) );
        $this->assertCount( 1, $this->db->calls, 'no update after a won insert' );
        $this->assertStringStartsWith( 'INSERT IGNORE INTO `wp_yaymatik_trigger_claims`', $this->db->calls[0][1] );
    }

    public function test_without_a_cooldown_a_held_claim_is_refused_without_an_update(): void {
        $this->db->results = [ 0 ];

        $this->assertFalse( TriggerClaimStore::claim( 3, 'order:12' ) );
        $this->assertCount( 1, $this->db->calls );
    }

    public function test_with_a_cooldown_only_an_older_claim_is_taken_again(): void {
        $this->db->results = [ 0, 1 ];
        $before            = time();

        $this->assertTrue( TriggerClaimStore::claim( 3, 'user:7', 600 ) );
        $sql = $this->db->last_sql();
        $this->assertMatchesRegularExpression( "/^UPDATE `wp_yaymatik_trigger_claims` SET claimed_at = '([0-9: -]+)' WHERE workflow_id = 3 AND subject = 'user:7' AND claimed_at < '([0-9: -]+)'$/", $sql );
        preg_match_all( "/'(\\d{4}-[0-9: -]+)'/", $sql, $times );
        $this->assertGreaterThanOrEqual( $before, strtotime( $times[1][0] . ' UTC' ) );
        $this->assertSame( 600, strtotime( $times[1][0] . ' UTC' ) - strtotime( $times[1][1] . ' UTC' ) );
    }

    public function test_two_claims_at_once_one_wins(): void {
        // Both inserts lose to the old row; the first update moves claimed_at, so the second matches no row.
        $this->db->results = [ 0, 1, 0, 0 ];

        $this->assertTrue( TriggerClaimStore::claim( 3, 'user:7', 600 ) );
        $this->assertFalse( TriggerClaimStore::claim( 3, 'user:7', 600 ) );
    }

    public function test_a_failed_write_is_refused_and_logged(): void {
        $this->db->results    = [ 0, false ];
        $this->db->last_error = 'gone away';

        $this->assertFalse( TriggerClaimStore::claim( 3, 'user:7', 600 ) );
        $this->assertStringContainsString( 'Could not claim a trigger subject: gone away', (string) file_get_contents( $this->log ) );
    }
}
