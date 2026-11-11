<?php
namespace YayMatik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use YayMatik\Tests\Support\FakeWpdb;
use YayMatik\Uninstaller;
use YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Core\Storage\Schema;

/** What uninstall deletes: always the jobs and transients; tables, options and meta only when asked. */
final class UninstallerTest extends TestCase {

    /** @var FakeWpdb */
    private $wpdb;

    protected function setUp(): void {
        require_once __DIR__ . '/../../includes/Uninstaller.php';
        $this->wpdb = new FakeWpdb();
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_sites'] );
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_tables_and_group_are_schemas_and_the_queues(): void {
        $schema = [];
        foreach ( ( new ReflectionClass( Schema::class ) )->getReflectionConstants() as $constant ) {
            if ( $constant->isPublic() ) {
                $schema[] = $constant->getValue();
            }
        }
        $this->assertEqualsCanonicalizing( $schema, Uninstaller::TABLES );
        $this->assertSame( ActionSchedulerQueue::GROUP, Uninstaller::GROUP );
    }

    public function test_without_the_constant_only_jobs_and_transients_go(): void {
        $this->wpdb->result = 'wp_actionscheduler_actions';
        Uninstaller::site( false );
        $sql = $this->queries();

        $this->assertCount( 4, $sql );
        $this->assertStringStartsWith( 'DELETE l FROM `wp_actionscheduler_logs` l JOIN `wp_actionscheduler_actions` a', $sql[0] );
        $this->assertStringContainsString( "g.slug = 'yaymatik'", $sql[0] );
        $this->assertStringStartsWith( 'DELETE a FROM `wp_actionscheduler_actions` a JOIN `wp_actionscheduler_groups` g', $sql[1] );
        $this->assertSame( "DELETE FROM `wp_actionscheduler_groups` WHERE slug = 'yaymatik'", $sql[2] );
        $this->assertSame( "DELETE FROM `wp_options` WHERE option_name LIKE '\\\\_transient\\\\_yaymatik\\\\_%' OR option_name LIKE '\\\\_transient\\\\_timeout\\\\_yaymatik\\\\_%'", $sql[3] );
    }

    public function test_without_action_scheduler_tables_only_transients_go(): void {
        Uninstaller::site( false );
        $this->assertCount( 1, $this->queries() );
        $this->assertStringContainsString( '_transient', $this->queries()[0] );
    }

    public function test_with_the_constant_tables_options_and_meta_go_but_coupons_stay(): void {
        // Every read takes the next result: the Action Scheduler check, 11 queries, the option names, 2 queries, then the wc_orders_meta check.
        $this->wpdb->results = array_merge( [ 'wp_actionscheduler_actions' ], array_fill( 0, 11, 1 ), [ [ 'yaymatik_settings' ] ], [ 1, 1, 'wp_wc_orders_meta' ] );
        Uninstaller::site( true );
        $sql = array_slice( $this->queries(), 4 );

        $drops = array_map( fn( $name ) => "DROP TABLE IF EXISTS `wp_yaymatik_{$name}`", Uninstaller::TABLES );
        $this->assertSame( $drops, array_slice( $sql, 0, 7 ) );
        $this->assertSame(
            [
                "DELETE FROM `wp_options` WHERE option_name LIKE 'yaymatik\\\\_%'",
                "DELETE FROM `wp_postmeta` WHERE meta_key LIKE '\\\\_yaymatik\\\\_%'",
                "DELETE FROM `wp_wc_orders_meta` WHERE meta_key LIKE '\\\\_yaymatik\\\\_%'",
            ],
            array_slice( $sql, 7 )
        );
        foreach ( $this->queries() as $query ) {
            $this->assertStringNotContainsString( 'wp_posts', $query );
        }
    }

    public function test_without_hpos_tables_order_meta_is_skipped(): void {
        Uninstaller::site( true );
        foreach ( $this->queries() as $query ) {
            $this->assertStringNotContainsString( 'wc_orders_meta', $query );
        }
    }

    public function test_each_site_of_a_network_is_cleaned(): void {
        $GLOBALS['yaymatik_test_sites'] = [ 1, 2 ];
        // Site 1: its check and 4 queries; then site 2's check.
        $this->wpdb->results = [ 'wp_actionscheduler_actions', 1, 1, 1, 1, 'wp_2_actionscheduler_actions' ];
        Uninstaller::uninstall();
        $sql = $this->queries();

        $this->assertStringContainsString( 'wp_actionscheduler_actions', $sql[1] );
        $this->assertStringContainsString( 'wp_2_actionscheduler_actions', $sql[5] );
        $this->assertSame( 'wp_', $this->wpdb->prefix );
    }

    /** The SQL sent with query(), in order. */
    private function queries(): array {
        return array_values( array_map( fn( $call ) => $call[1], array_filter( $this->wpdb->calls, fn( $call ) => 'query' === $call[0] ) ) );
    }
}
