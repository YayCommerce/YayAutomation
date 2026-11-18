<?php
namespace YayMatik\Tests\Unit\Core\Runtime;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Runtime\Cleanup;
use YayMatik\Workflow\Core\Runtime\DailyJobs;
use YayMatik\Workflow\Core\Runtime\HealthCheck;

require_once dirname( __DIR__, 3 ) . '/Support/action-scheduler-stubs.php';

/** Daily jobs added beside core's: their health check fix, and the jobs left over when none is added. */
final class DailyJobsTest extends TestCase {

    protected function tearDown(): void {
        ( new \ReflectionProperty( DailyJobs::class, 'jobs' ) )->setValue( null, [] );
        unset( $GLOBALS['yay_test_as'] );
    }

    public function test_an_added_job_gets_the_schedule_fix(): void {
        $this->assertArrayNotHasKey( 'example_job', HealthCheck::fixes() );

        DailyJobs::add( 'example_job', 'yaymatik_example', 60, 'time' );

        $this->assertSame( 'schedule', HealthCheck::fixes()['example_job'] );
        $this->assertSame( 'enqueue', HealthCheck::fixes()['runs_queued'] );
    }

    public function test_left_over_sweep_jobs_go_when_the_sweep_is_not_added(): void {
        as_schedule_single_action( 1, 'yaymatik_inactive_sweep', [], 'yaymatik' );
        as_schedule_single_action( 1, 'yaymatik_inactive_sweep_batch', [ 90, 2 ], 'yaymatik' );
        as_schedule_single_action( 1, 'yaymatik_cleanup', [], 'yaymatik' );

        Cleanup::unschedule_left_over();

        $this->assertSame( [ 'canceled', 'canceled', 'pending' ], array_column( $GLOBALS['yay_test_as'], 'status' ) );
    }

    public function test_an_added_sweep_keeps_its_jobs(): void {
        DailyJobs::add( 'inactive_sweep_job', 'yaymatik_inactive_sweep', 60, 'time' );
        as_schedule_single_action( 1, 'yaymatik_inactive_sweep_batch', [ 90, 2 ], 'yaymatik' );

        Cleanup::unschedule_left_over();

        $this->assertSame( [ 'pending' ], array_column( $GLOBALS['yay_test_as'], 'status' ) );
    }
}
