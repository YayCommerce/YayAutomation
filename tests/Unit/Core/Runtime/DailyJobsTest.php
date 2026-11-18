<?php
namespace YayMatik\Tests\Unit\Core\Runtime;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Runtime\Cleanup;
use YayMatik\Workflow\Core\Runtime\DailyJobs;
use YayMatik\Workflow\Core\Runtime\HealthCheck;

require_once dirname( __DIR__, 3 ) . '/Support/action-scheduler-stubs.php';

/** Daily jobs added beside core's: when they're scheduled, their health check fix, and the jobs left over when none is added. */
final class DailyJobsTest extends TestCase {

    protected function tearDown(): void {
        ( new \ReflectionProperty( DailyJobs::class, 'jobs' ) )->setValue( null, [] );
        unset( $GLOBALS['yay_test_as'] );
        $GLOBALS['yaymatik_test_options'] = [];
    }

    /**
     * @param callable|null $needed
     */
    private static function add_sweep( ?callable $needed = null ): void {
        DailyJobs::add( 'inactive_sweep_job', 'yaymatik_inactive_sweep', 60, fn() => as_schedule_recurring_action( time() + 60, 86400, 'yaymatik_inactive_sweep', [], 'yaymatik' ), $needed );
    }

    private static function sweep_jobs(): int {
        return count(
            as_get_scheduled_actions(
                [
                    'hook'   => 'yaymatik_inactive_sweep',
                    'args'   => null,
                    'group'  => 'yaymatik',
                    'status' => 'pending',
                ]
            )
        );
    }

    public function test_a_job_added_after_the_check_is_scheduled_without_the_hourly_wait(): void {
        Cleanup::ensure();
        self::add_sweep();
        Cleanup::ensure();

        $this->assertSame( 1, self::sweep_jobs() );
    }

    public function test_with_the_same_jobs_the_hourly_wait_holds(): void {
        self::add_sweep();
        Cleanup::ensure();
        as_unschedule_all_actions( 'yaymatik_inactive_sweep' );
        Cleanup::ensure();

        $this->assertSame( 0, self::sweep_jobs() );
    }

    public function test_a_license_turned_off_then_on_schedules_the_sweep_again(): void {
        self::add_sweep();
        Cleanup::ensure();
        as_unschedule_all_actions( 'yaymatik_inactive_sweep' );
        ( new \ReflectionProperty( DailyJobs::class, 'jobs' ) )->setValue( null, [] );
        Cleanup::ensure();
        self::add_sweep();
        Cleanup::ensure();

        $this->assertSame( 1, self::sweep_jobs() );
    }

    public function test_a_job_with_no_work_has_no_check_and_no_fix(): void {
        self::add_sweep( fn() => false );

        $this->assertSame( [], DailyJobs::needed() );
        $this->assertArrayNotHasKey( 'inactive_sweep_job', HealthCheck::fixes() );
        $this->assertSame( 0, HealthCheck::fix( 'inactive_sweep_job' ) );
        $this->assertSame( 0, self::sweep_jobs() );
    }

    public function test_a_missing_job_with_work_is_scheduled_again_by_its_fix(): void {
        self::add_sweep( fn() => true );

        $this->assertSame( [ 'inactive_sweep_job' ], array_keys( DailyJobs::needed() ) );
        $this->assertSame( 1, HealthCheck::fix( 'inactive_sweep_job' ) );
        $this->assertSame( 1, self::sweep_jobs() );
        $this->assertSame( 0, HealthCheck::fix( 'inactive_sweep_job' ) );
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
