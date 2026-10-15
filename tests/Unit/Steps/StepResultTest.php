<?php
namespace YayMatik\Tests\Unit\Steps;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Steps\Core\StepResult;

/** What a step's run() returns. */
final class StepResultTest extends TestCase {

    public function test_ok_carries_outputs(): void {
        $result = StepResult::ok( [ 'result' => 'yes' ] );

        $this->assertSame( StepResult::OK, $result->status() );
        $this->assertTrue( $result->is_ok() );
        $this->assertSame( [ 'result' => 'yes' ], $result->outputs() );
        $this->assertSame( [], StepResult::ok()->outputs() );
        $this->assertSame( '', $result->message() );
    }

    public function test_skipped_carries_a_reason(): void {
        $result = StepResult::skipped( 'The order was deleted.' );

        $this->assertTrue( $result->is_skipped() );
        $this->assertFalse( $result->is_failed() );
        $this->assertSame( 'The order was deleted.', $result->message() );
        $this->assertSame( [], $result->outputs() );
    }

    public function test_failed_takes_a_message_or_a_wp_error(): void {
        $plain = StepResult::failed( 'No mail server.' );
        $error = StepResult::failed( new \WP_Error( 'yaymatik_mail_failed', 'The email could not be sent.' ) );

        $this->assertTrue( $plain->is_failed() );
        $this->assertSame( 'No mail server.', $plain->message() );
        $this->assertSame( '', $plain->code() );
        $this->assertSame( 'yaymatik_mail_failed', $error->code() );
        $this->assertSame( 'The email could not be sent.', $error->message() );
    }

    public function test_wait_carries_the_time_to_resume(): void {
        $result = StepResult::wait( 1760000000 );

        $this->assertTrue( $result->is_wait() );
        $this->assertSame( StepResult::WAIT, $result->status() );
        $this->assertSame( 1760000000, $result->until() );
        $this->assertSame( 0, StepResult::ok()->until() );
    }
}
