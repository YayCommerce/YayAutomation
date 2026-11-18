<?php
namespace YayMatik\Tests\Unit\Pro\Workflow\Core\Runtime;

use PHPUnit\Framework\TestCase;
use YayMatik\Pro\Workflow\Core\Runtime\InactiveSweep;
use YayMatik\Tests\Support\FakeWpdb;
use YayMatik\Workflow\Core\Storage\WorkflowStore;

/** Which days a sweep looks for, how far back, and when it has work. */
final class InactiveSweepTest extends TestCase {

    protected function tearDown(): void {
        $GLOBALS['yaymatik_test_options'] = [];
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_needed_while_an_active_workflow_starts_on_customer_inactive_or_when_unknown(): void {
        update_option( WorkflowStore::ACTIVE_TRIGGERS_OPTION, [ 'customer_inactive', 'order_created' ] );
        $this->assertTrue( InactiveSweep::needed() );
        update_option( WorkflowStore::ACTIVE_TRIGGERS_OPTION, [ 'order_created' ] );
        $this->assertFalse( InactiveSweep::needed() );

        $GLOBALS['yaymatik_test_options'] = [];
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
        $GLOBALS['wpdb']             = new FakeWpdb();
        $GLOBALS['wpdb']->last_error = 'Table missing';
        $this->assertTrue( InactiveSweep::needed() );
    }

    /**
     * @param int|null $days
     */
    private static function workflow( ?int $days ): array {
        return [
            'graph' => [
                'nodes' => [
                    [
                        'id'   => 'a',
                        'kind' => 'action',
                        'data' => [ 'config' => [ 'days' => 1 ] ],
                    ],
                    [
                        'id'   => 't',
                        'kind' => 'trigger',
                        'data' => [ 'config' => null === $days ? [] : [ 'days' => $days ] ],
                    ],
                ],
            ],
        ];
    }

    public function test_days_of_reads_the_trigger_node_and_keeps_each_value_once(): void {
        $this->assertSame( [ 90, 30 ], InactiveSweep::days_of( [ self::workflow( 90 ), self::workflow( 30 ), self::workflow( 90 ) ] ) );
    }

    public function test_days_of_drops_a_workflow_without_days(): void {
        $this->assertSame( [], InactiveSweep::days_of( [ self::workflow( null ), [ 'graph' => [] ] ] ) );
    }

    public function test_window_runs_from_days_plus_seven_to_days_ago_in_timestamps(): void {
        $now = 1791802800;

        $this->assertSame( ( $now - 97 * 86400 ) . '...' . ( $now - 90 * 86400 ), InactiveSweep::window( 90, $now ) );
    }
}
