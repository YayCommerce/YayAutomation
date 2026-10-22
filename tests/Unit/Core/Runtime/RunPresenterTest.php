<?php
namespace YayMatik\Tests\Unit\Core\Runtime;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeOrder;
use YayMatik\Workflow\Core\Runtime\RunPresenter;

require_once dirname( __DIR__, 3 ) . '/Support/woocommerce-stubs.php';

/** The REST shape of runs and run stats: camelCase, ISO dates in UTC, no scope or cursor. */
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

    public function test_item_has_camel_case_keys_subject_and_steps_run_and_no_scope_or_position(): void {
        $item = RunPresenter::item( self::run_row(), 3 );

        $this->assertSame(
            [ 'id', 'workflowId', 'versionId', 'triggerId', 'subject', 'status', 'error', 'waitUntil', 'parentRunId', 'rootRunId', 'depth', 'startedAt', 'finishedAt', 'subjectLabel', 'subjectUrl', 'stepsRun' ],
            array_keys( $item )
        );
        $this->assertSame( '1695', $item['workflowId'] );
        $this->assertNull( $item['parentRunId'] );
        $this->assertSame( '19', $item['rootRunId'] );
        $this->assertSame( 'Order #1693', $item['subjectLabel'], 'an order the store no longer has' );
        $this->assertNull( $item['subjectUrl'] );
        $this->assertSame( 3, $item['stepsRun'] );
    }

    public function test_dates_are_iso_utc_and_missing_dates_null(): void {
        $run = RunPresenter::detail( self::run_row(), [], null );

        $this->assertSame( '2026-10-12T08:03:04+00:00', $run['startedAt'] );
        $this->assertSame( '2026-10-13T08:00:00+00:00', $run['waitUntil'] );
        $this->assertNull( $run['finishedAt'] );
        $this->assertNull( RunPresenter::iso( '' ) );
        $this->assertNull( RunPresenter::iso( '0000-00-00 00:00:00' ) );
    }

    public function test_subject_reads_the_real_order_number_and_null_url_once_the_order_is_gone(): void {
        $order                  = new FakeOrder(
            [
                'id'           => 1693,
                'order_number' => 'WC-77',
            ]
        );
        FakeOrder::$found[1693] = $order;
        $found                  = RunPresenter::subject( 'order:1693' );
        unset( FakeOrder::$found[1693] );
        $this->assertSame( 'Order #WC-77', $found['label'] );
        $this->assertSame( $order->get_edit_order_url(), $found['url'] );

        $this->assertSame(
            [
                'label' => 'Order #1693',
                'url'   => null,
            ],
            RunPresenter::subject( 'order:1693' )
        );
        $this->assertSame(
            [
                'label' => 'cart:5',
                'url'   => null,
            ],
            RunPresenter::subject( 'cart:5' )
        );
    }

    public function test_detail_adds_steps_in_the_rest_shape_with_their_step_type(): void {
        $row                  = self::run_row();
        $row['parent_run_id'] = 7;
        $gone                 = [ 'step_id' => 'removed' ] + self::step_row();
        $unset                = [ 'step_id' => 'blank' ] + self::step_row();
        $graph                = [
            'nodes' => [
                [
                    'id'   => 'email',
                    'data' => [ 'block' => 'send_email' ],
                ],
                [
                    'id'   => 'blank',
                    'data' => [ 'block' => null ],
                ],
            ],
        ];
        $detail               = RunPresenter::detail( $row, [ self::step_row(), $gone, $unset ], $graph );

        $this->assertSame( '7', $detail['parentRunId'] );
        $this->assertArrayNotHasKey( 'position', $detail );
        $this->assertArrayNotHasKey( 'scope', $detail );
        $step = $detail['steps'][0];
        $this->assertSame(
            [ 'id', 'runId', 'stepId', 'iteration', 'status', 'input', 'output', 'error', 'attempt', 'startedAt', 'finishedAt', 'stepType' ],
            array_keys( $step )
        );
        $this->assertSame( '19', $step['runId'] );
        $this->assertSame( 'email', $step['stepId'] );
        $this->assertSame( 'send_email', $step['stepType'] );
        $this->assertNull( $detail['steps'][1]['stepType'] );
        $this->assertNull( $detail['steps'][2]['stepType'] );
        $this->assertNull( RunPresenter::detail( $row, [ self::step_row() ], null )['steps'][0]['stepType'] );
        $this->assertSame( '2026-10-12T08:03:05+00:00', $step['finishedAt'] );
        // Objects, so an empty output is `{}` in JSON, not `[]`.
        $this->assertSame( '{}', wp_json_encode( $step['output'] ) );
        $this->assertSame( '{"subject":"Order {{ order.number }}"}', wp_json_encode( $step['input'] ) );
    }

    public function test_stats_are_zero_for_a_workflow_that_never_ran(): void {
        $this->assertSame(
            [
                'runs30d'     => 0,
                'lastRunAt'   => null,
                'waitingRuns' => 0,
            ],
            RunPresenter::stats( null )
        );
        $this->assertSame(
            [
                'runs30d'     => 4,
                'lastRunAt'   => '2026-10-12T08:03:04+00:00',
                'waitingRuns' => 1,
            ],
            RunPresenter::stats(
                [
                    'recent'   => 4,
                    'last_run' => '2026-10-12 08:03:04',
                    'waiting'  => 1,
                ]
            )
        );
    }
}
