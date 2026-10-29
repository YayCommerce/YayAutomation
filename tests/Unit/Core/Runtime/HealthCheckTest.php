<?php
namespace YayMatik\Tests\Unit\Core\Runtime;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Runtime\HealthCheck;

/** Which runs count as stuck: overdue, and no job that would take them. */
final class HealthCheckTest extends TestCase {

    private const NOW = 1791802800;

    /**
     * @param int      $id
     * @param string   $status
     * @param int      $started_ago Seconds.
     * @param int|null $wait_ago Seconds past `wait_until`; null = none.
     */
    private static function row( int $id, string $status, int $started_ago = 0, ?int $wait_ago = null ): array {
        return [
            'id'         => (string) $id,
            'status'     => $status,
            'started_at' => gmdate( 'Y-m-d H:i:s', self::NOW - $started_ago ),
            'wait_until' => null === $wait_ago ? null : gmdate( 'Y-m-d H:i:s', self::NOW - $wait_ago ),
        ];
    }

    /**
     * @param array $runs
     * @param array $jobs [ run_id => hooks with a pending or in-progress job ].
     */
    private static function classify( array $runs, array $jobs = [] ): array {
        return HealthCheck::classify(
            $runs,
            fn( int $run_id, string $hook ) => in_array( $hook, $jobs[ $run_id ] ?? [], true ),
            self::NOW
        );
    }

    public function test_queued_is_stuck_after_five_minutes_without_a_job(): void {
        $found = self::classify( [ self::row( 1, 'queued', 4 * 60 ), self::row( 2, 'queued', 6 * 60 ) ] );

        $this->assertSame(
            [
                'count' => 1,
                'ids'   => [ 2 ],
            ],
            $found['runs_queued']
        );
    }

    public function test_queued_with_a_run_or_recovery_job_is_fine(): void {
        $runs  = [ self::row( 2, 'queued', 6 * 60 ), self::row( 3, 'queued', 6 * 60 ) ];
        $found = self::classify(
            $runs,
            [
                2 => [ 'yaymatik_run' ],
                3 => [ 'yaymatik_recover_run' ],
            ]
        );

        $this->assertSame( 0, $found['runs_queued']['count'] );
    }

    public function test_waiting_is_stuck_fifteen_minutes_past_its_wait(): void {
        $found = self::classify( [ self::row( 1, 'waiting', 3600, 10 * 60 ), self::row( 2, 'waiting', 3600, 20 * 60 ) ] );

        $this->assertSame( [ 2 ], $found['runs_queued']['ids'] );
    }

    /** An overdue pending job still counts as a job: late jobs are their own check. */
    public function test_waiting_with_an_overdue_job_is_fine(): void {
        $found = self::classify( [ self::row( 2, 'waiting', 3600, 20 * 60 ) ], [ 2 => [ 'yaymatik_run' ] ] );

        $this->assertSame( 0, $found['runs_queued']['count'] );
    }

    public function test_running_needs_a_run_or_recovery_job(): void {
        $runs  = [ self::row( 1, 'running' ), self::row( 2, 'running' ), self::row( 3, 'running' ) ];
        $found = self::classify(
            $runs,
            [
                1 => [ 'yaymatik_run' ],
                2 => [ 'yaymatik_recover_run' ],
            ]
        );

        $this->assertSame(
            [
                'count' => 1,
                'ids'   => [ 3 ],
            ],
            $found['runs_running']
        );
        $this->assertSame( 0, $found['runs_queued']['count'] );
    }

    public function test_ids_capped_at_fifty_count_is_all(): void {
        $runs = array_map( fn( int $id ) => self::row( $id, 'queued', 600 ), range( 1, 60 ) );

        $found = self::classify( $runs );

        $this->assertSame( 60, $found['runs_queued']['count'] );
        $this->assertSame( range( 1, 50 ), $found['runs_queued']['ids'] );
        $this->assertArrayNotHasKey( 'capped', $found['runs_queued'] );
    }

    /** 500 candidates = the scan was full: more may be stuck, so both counts are lower bounds. */
    public function test_full_scan_is_capped(): void {
        $runs = array_map( fn( int $id ) => self::row( $id, 'queued', 600 ), range( 1, 500 ) );

        $found = self::classify( $runs );

        $this->assertSame( 500, $found['runs_queued']['count'] );
        $this->assertTrue( $found['runs_queued']['capped'] );
        $this->assertTrue( $found['runs_running']['capped'] );
        $this->assertArrayNotHasKey( 'capped', self::classify( array_slice( $runs, 0, 499 ) )['runs_queued'] );
    }
}
