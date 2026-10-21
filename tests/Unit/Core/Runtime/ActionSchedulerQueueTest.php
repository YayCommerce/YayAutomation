<?php
namespace YayMatik\Tests\Unit\Core\Runtime;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue;

require_once dirname( __DIR__, 3 ) . '/Support/action-scheduler-stubs.php';

/** One job per run: no second pending run job, no second recovery job. */
final class ActionSchedulerQueueTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yay_test_as'] = [];
    }

    /** Jobs as [ hook, args, at|null ]. */
    private static function jobs(): array {
        return array_map( fn( array $job ) => [ $job['hook'], $job['args'], $job['at'] ], $GLOBALS['yay_test_as'] );
    }

    public function test_enqueue_adds_one_pending_job_per_run(): void {
        $queue = new ActionSchedulerQueue();

        $this->assertTrue( $queue->enqueue( 5, 1791802800 ) );
        $this->assertTrue( $queue->enqueue( 5 ) );
        $this->assertTrue( $queue->enqueue( 6 ) );

        $this->assertSame(
            [ [ 'yaymatik_run', [ 5 ], 1791802800 ], [ 'yaymatik_run', [ 6 ], null ] ],
            self::jobs()
        );
    }

    public function test_job_in_progress_doesnt_block_the_next_one(): void {
        $queue = new ActionSchedulerQueue();
        $queue->enqueue( 5 );
        $GLOBALS['yay_test_as'][0]['status'] = 'in-progress';

        $queue->enqueue( 5 );

        $this->assertCount( 2, self::jobs() );
    }

    public function test_recover_adds_no_second_recovery_job_while_one_is_pending_or_running(): void {
        $queue = new ActionSchedulerQueue();

        $this->assertTrue( $queue->recover( 5 ) );
        $this->assertTrue( $queue->recover( 5 ) );
        $GLOBALS['yay_test_as'][0]['status'] = 'in-progress';
        $this->assertTrue( $queue->recover( 5 ) );
        $this->assertCount( 1, self::jobs() );

        $GLOBALS['yay_test_as'][0]['status'] = 'complete';
        $queue->recover( 5 );
        $this->assertSame( 'yaymatik_recover_run', self::jobs()[1][0] );
    }
}
