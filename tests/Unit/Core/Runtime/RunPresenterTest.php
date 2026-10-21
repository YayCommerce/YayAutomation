<?php
namespace YayMatik\Tests\Unit\Core\Runtime;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Runtime\RunPresenter;

/** The REST shape of runs: camelCase, ISO dates in UTC, no scope or cursor. */
final class RunPresenterTest extends TestCase {

    private static function run_row(): array {
        return [
            'id'            => 19,
            'workflow_id'   => 1695,
            'version_id'    => 126,
            'trigger_id'    => 'order_created',
            'subject'       => 'order:1693',
            'status'        => 'waiting',
            'position'      => [ 'queue' => [ 'w' ] ],
            'scope'         => [ 'trigger' => [ 'order' => [ '__ref' => [] ] ] ],
            'wait_until'    => '2026-10-13 08:00:00',
            'error'         => null,
            'parent_run_id' => 0,
            'root_run_id'   => 19,
            'depth'         => 0,
            'started_at'    => '2026-10-12 08:03:04',
            'finished_at'   => null,
        ];
    }

    private static function step_row(): array {
        return [
            'id'          => 3,
            'run_id'      => 19,
            'step_id'     => 'email',
            'iteration'   => 0,
            'status'      => 'failed',
            'input'       => [ 'subject' => 'Order {{ order.number }}' ],
            'output'      => [],
            'error'       => 'Mail server down.',
            'attempt'     => 1,
            'started_at'  => '2026-10-12 08:03:04',
            'finished_at' => '2026-10-12 08:03:05',
        ];
    }

    public function test_run_has_camel_case_keys_and_no_scope_or_position(): void {
        $run = RunPresenter::run( self::run_row() );

        $this->assertSame(
            [ 'id', 'workflowId', 'versionId', 'triggerId', 'subject', 'status', 'error', 'waitUntil', 'parentRunId', 'rootRunId', 'depth', 'startedAt', 'finishedAt' ],
            array_keys( $run )
        );
        $this->assertSame( '1695', $run['workflowId'] );
        $this->assertNull( $run['parentRunId'] );
        $this->assertSame( '19', $run['rootRunId'] );
    }

    public function test_dates_are_iso_utc_and_missing_dates_null(): void {
        $run = RunPresenter::run( self::run_row() );

        $this->assertSame( '2026-10-12T08:03:04+00:00', $run['startedAt'] );
        $this->assertSame( '2026-10-13T08:00:00+00:00', $run['waitUntil'] );
        $this->assertNull( $run['finishedAt'] );
        $this->assertNull( RunPresenter::iso( '' ) );
        $this->assertNull( RunPresenter::iso( '0000-00-00 00:00:00' ) );
    }

    public function test_detail_adds_steps_in_the_rest_shape(): void {
        $row                  = self::run_row();
        $row['parent_run_id'] = 7;
        $detail               = RunPresenter::detail( $row, [ self::step_row() ] );

        $this->assertSame( '7', $detail['parentRunId'] );
        $this->assertArrayNotHasKey( 'position', $detail );
        $this->assertArrayNotHasKey( 'scope', $detail );
        $step = $detail['steps'][0];
        $this->assertSame(
            [ 'id', 'runId', 'stepId', 'iteration', 'status', 'input', 'output', 'error', 'attempt', 'startedAt', 'finishedAt' ],
            array_keys( $step )
        );
        $this->assertSame( '19', $step['runId'] );
        $this->assertSame( 'email', $step['stepId'] );
        $this->assertSame( '2026-10-12T08:03:05+00:00', $step['finishedAt'] );
        // Objects, so an empty output is `{}` in JSON, not `[]`.
        $this->assertSame( '{}', wp_json_encode( $step['output'] ) );
        $this->assertSame( '{"subject":"Order {{ order.number }}"}', wp_json_encode( $step['input'] ) );
    }
}
