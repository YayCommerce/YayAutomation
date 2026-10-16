<?php
/**
 * E2E for runs, through real WooCommerce events: the Dispatcher queues runs, each run's job is then
 * run right away by the Worker (its Action Scheduler job is removed). Prints PASS / FAIL lines.
 * Cleans up its order, workflows, runs (by root run, so child runs too) and jobs. Run through
 * check-runs.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

require_once __DIR__ . '/check-run.php';

use YayMatik\Workflow\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Runtime\Runtime;
use YayMatik\Workflow\Steps\Core\StepRegistry;
use YayMatik\Workflow\Storage\RunStepStore;
use YayMatik\Workflow\Storage\RunStore;

const CHECK_META  = '_yaymatik_check_runs';
const CHECK_STAFF = 'staff-check@example.test';

global $wpdb;
wp_set_current_user( 1 );
if ( ! Runtime::worker() ) {
    WP_CLI::error( 'Runtime not booted (WooCommerce off?).' );
}

$fails = 0;
$ok    = function ( bool $pass, string $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $fails += $pass ? 0 : 1;
};

$mails     = [];
$fail_mail = false;
add_filter(
    'pre_wp_mail',
    function ( $short_circuit, $atts ) use ( &$mails, &$fail_mail ) {
        if ( $fail_mail ) {
            return false;
        }
        $mails[] = $atts;
        return true;
    },
    10,
    2
);

$call = function ( string $method, string $route, ?array $body = null ) {
    $request = new WP_REST_Request( $method, '/yaymatik/v1/' . $route );
    if ( null !== $body ) {
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( wp_json_encode( $body ) );
    }
    return rest_do_request( $request )->get_data();
};

/** Trigger + a chain of actions, as the editor saves it. */
$graph = function ( string $trigger, array $trigger_config, array $actions ) {
    $nodes = [
        [
            'id'       => 'trigger',
            'kind'     => 'trigger',
            'position' => [
                'x' => 0,
                'y' => 0,
            ],
            'data'     => [
                'block'  => $trigger,
                'config' => (object) $trigger_config,
            ],
        ],
    ];
    $edges = [];
    $prev  = 'trigger';
    foreach ( $actions as $i => list( $id, $block, $config ) ) {
        $nodes[] = [
            'id'       => $id,
            'kind'     => 'action',
            'position' => [
                'x' => 0,
                'y' => 170 * ( $i + 1 ),
            ],
            'data'     => [
                'block'  => $block,
                'config' => (object) $config,
            ],
        ];
        $edges[] = [
            'id'     => "e{$i}",
            'source' => $prev,
            'target' => $id,
        ];
        $prev    = $id;
    }
    return [
        'nodes' => $nodes,
        'edges' => $edges,
    ];
};

$workflows = [];
$create    = function ( string $name, array $graph ) use ( $call, &$workflows ) {
    $workflow = $call(
        'POST',
        'workflows',
        [
            'name'  => "Check runs: {$name} (temporary)",
            'graph' => $graph,
        ]
    );
    $id       = (int) $workflow['id'];
    $call( 'POST', "workflows/{$id}/activate" );
    $workflows[] = $id;
    return $id;
};

// Leftovers of an earlier, interrupted run would match this run's events too.
$ok( ! yaymatik_check_remove_workflows( 'Check runs:' ), 'setup: no temporary workflows left from earlier runs' );

$since = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . RunStore::table() );

/**
 * Starts the runs for the events so far; returns the new runs of `$workflow_id`, queued. Other
 * active workflows may match the temporary order too: their runs are removed at the end.
 */
$dispatch = function ( int $workflow_id ) use ( $wpdb ) {
    $before = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . RunStore::table() );
    Runtime::dispatcher()->flush();
    $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . RunStore::table() . ' WHERE id > %d AND workflow_id = %d ORDER BY id', $before, $workflow_id ) );
    return array_map( 'intval', $ids );
};

/** The run's job, now; its Action Scheduler job is removed. */
$run_job = function ( int $run_id ) {
    as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $run_id ], ActionSchedulerQueue::GROUP );
    Runtime::worker()->run( $run_id );
    return RunStore::find( $run_id );
};

$notes = fn( WC_Order $order ) => wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' );

try {
    // Workflows first, so the order's own events find them.
    $created_wf = $create( 'order created', $graph( 'order_created', [], [ [ 'note', 'add_order_note', [ 'note' => 'Check-runs: created {{ order.number }}' ] ] ] ) );
    $status_wf  = $create(
        'status flow',
        $graph(
            'order_status_changed',
            [ 'to' => [ 'on-hold' ] ],
            [
                [
                    'email',
                    'send_internal_email',
                    [
                        'recipients' => CHECK_STAFF,
                        'subject'    => 'Check-runs {{ order.number }}',
                        'message'    => 'Order {{ order.number }} for {{ customer.full_name }} ({{ customer.email }}), total {{ order.total }}',
                    ],
                ],
                [ 'status', 'change_order_status', [ 'status' => 'failed' ] ],
                [
                    'meta',
                    'update_order_meta',
                    [
                        'key'   => CHECK_META,
                        'value' => 'Order {{ order.number }} for {{ customer.email }}',
                    ],
                ],
                [ 'note', 'add_order_note', [ 'note' => 'Check-runs: order {{ order.number }} is {{ order.status }}' ] ],
            ]
        )
    );
    $version_wf = $create( 'version', $graph( 'order_status_changed', [ 'to' => [ 'processing' ] ], [ [ 'note', 'add_order_note', [ 'note' => 'Check-runs v1' ] ] ] ) );

    // 1. Order created → note.
    $order = wc_create_order( [ 'status' => 'pending' ] );
    $order->set_billing_first_name( 'Zed' );
    $order->set_billing_last_name( 'Checkrun' );
    $order->set_billing_email( 'zed.checkrun@example.test' );
    $order->set_total( 123.45 );
    $order->save();
    $runs = $dispatch( $created_wf );
    $ok( 1 === count( $runs ), 'order created: one run queued' );
    $run = $runs ? $run_job( $runs[0] ) : null;
    $ok( $run && 'completed' === $run['status'], 'order created: run completed' );
    $ok( in_array( 'Check-runs: created ' . $order->get_order_number(), $notes( $order ), true ), 'order created: note added' );

    // 2. Status changed → staff email, status, meta, note.
    $mails = [];
    $order->update_status( 'on-hold' );
    $runs = $dispatch( $status_wf );
    $ok( 1 === count( $runs ), 'status changed: one run queued' );
    $run   = $runs ? $run_job( $runs[0] ) : null;
    $order = wc_get_order( $order->get_id() );
    $steps = $run ? RunStepStore::for_run( (int) $run['id'] ) : [];
    $ok( $run && 'completed' === $run['status'], 'status changed: run completed' );
    $ok( 'failed' === $order->get_status(), 'status changed: status set to failed' );
    $ok( 'Order ' . $order->get_order_number() . ' for zed.checkrun@example.test' === $order->get_meta( CHECK_META ), 'status changed: meta set' );
    $ok( in_array( 'Check-runs: order ' . $order->get_order_number() . ' is Failed', $notes( $order ), true ), 'status changed: note added' );
    $staff = array_values( array_filter( $mails, fn( $m ) => in_array( CHECK_STAFF, (array) $m['to'], true ) ) );
    $ok( 1 === count( $staff ), 'status changed: staff email sent' );
    $ok( [ 'email', 'status', 'meta', 'note' ] === array_column( $steps, 'step_id' ) && [ 'completed' ] === array_values( array_unique( array_column( $steps, 'status' ) ) ), 'status changed: one completed run_steps row per step' );
    $node_block = [
        'email'  => 'send_internal_email',
        'status' => 'change_order_status',
        'meta'   => 'update_order_meta',
        'note'   => 'add_order_note',
    ];
    $declared   = true;
    foreach ( $steps as $step ) {
        $keys     = array_keys( StepRegistry::get( $node_block[ $step['step_id'] ] )->outputs() );
        $declared = $declared && array_keys( $step['output'] ) === $keys;
    }
    $ok( $steps && $declared, 'status changed: outputs exactly as declared' );
    $ok( 'Order {{ order.number }} for {{ customer.full_name }} ({{ customer.email }}), total {{ order.total }}' === ( $steps[0]['input']['message'] ?? '' ), 'status changed: input keeps the unrendered template' );

    // Privacy: values rendered from the order are in the mail, never in runs or run_steps.
    $body    = html_entity_decode( wp_strip_all_tags( (string) ( $staff[0]['message'] ?? '' ) ) );
    $total   = html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ) ) );
    $needles = [ 'Zed Checkrun', 'zed.checkrun@example.test', $total ];
    $ok( count( array_filter( $needles, fn( $n ) => false !== strpos( $body, $n ) ) ) === count( $needles ), 'privacy: mail holds the rendered name, email and total (' . $total . ')' );
    $stored = $wpdb->get_col( $wpdb->prepare( 'SELECT CONCAT_WS(" ", scope, position, error) FROM ' . RunStore::table() . ' WHERE id > %d', $since ) );
    $stored = array_merge( $stored, $wpdb->get_col( $wpdb->prepare( 'SELECT CONCAT_WS(" ", s.input, s.output, s.error) FROM ' . RunStepStore::table() . ' s JOIN ' . RunStore::table() . ' r ON r.id = s.run_id WHERE r.id > %d', $since ) ) );
    $leaks  = array_filter( $needles, fn( $n ) => false !== strpos( implode( "\n", $stored ), $n ) );
    $ok( ! $leaks, 'privacy: no rendered value in any run scope or step input/output' . ( $leaks ? ': ' . implode( ', ', $leaks ) : '' ) );

    // 3. Forced mail failure → run failed, later steps not run.
    $order->delete_meta_data( CHECK_META );
    $order->save();
    $order->update_status( 'on-hold' );
    $fail_mail  = true;
    $notes_then = $notes( $order );
    $runs       = $dispatch( $status_wf );
    $run        = $runs ? $run_job( $runs[0] ) : null;
    $fail_mail  = false;
    $order      = wc_get_order( $order->get_id() );
    $steps      = $run ? RunStepStore::for_run( (int) $run['id'] ) : [];
    $ok( $run && 'failed' === $run['status'] && 'Couldn’t send the email.' === $run['error'], 'mail failure: run failed with the email step\'s error (' . ( $run['error'] ?? '' ) . ')' );
    $ok( [ 'email' ] === array_column( $steps, 'step_id' ) && 'failed' === $steps[0]['status'], 'mail failure: only the email step ran, as failed' );
    $ok( 'on-hold' === $order->get_status() && '' === $order->get_meta( CHECK_META ) && $notes( $order ) === $notes_then, 'mail failure: later steps did not run (status, meta, note unchanged)' );

    // 4. Live graph changed after queueing → the run follows the version it pinned.
    $order->update_status( 'processing' );
    $runs = $dispatch( $version_wf );
    $call( 'PUT', "workflows/{$version_wf}", [ 'graph' => $graph( 'order_status_changed', [ 'to' => [ 'processing' ] ], [ [ 'note', 'add_order_note', [ 'note' => 'Check-runs v2' ] ] ] ) ] );
    $applied = $call( 'POST', "workflows/{$version_wf}/apply" );
    $run     = $runs ? $run_job( $runs[0] ) : null;
    $all     = $notes( wc_get_order( $order->get_id() ) );
    $ok( 'Check-runs v2' === ( $applied['graph']['nodes'][1]['data']['config']['note'] ?? '' ), 'version: live graph changed to v2 after queueing' );
    $ok( $run && 'completed' === $run['status'] && in_array( 'Check-runs v1', $all, true ) && ! in_array( 'Check-runs v2', $all, true ), 'version: run followed the pinned v1' );

    // 5. Workflow turned off before the job → cancelled.
    $order = wc_get_order( $order->get_id() );
    $order->update_status( 'pending' );
    $order->update_status( 'processing' );
    $runs = $dispatch( $version_wf );
    $call( 'POST', "workflows/{$version_wf}/deactivate" );
    $before = $notes( wc_get_order( $order->get_id() ) );
    $run    = $runs ? $run_job( $runs[0] ) : null;
    $ok( $run && 'cancelled' === $run['status'], 'off before the job: run cancelled' );
    $ok( $notes( wc_get_order( $order->get_id() ) ) === $before, 'off before the job: no step ran' );

    // 6. Recovery on the real table: a run left `running` with its step `running` (its job died).
    $workflow = YayMatik\Workflow\Storage\WorkflowRepository::find( $created_wf );
    $run_id   = Runtime::dispatcher()->start_run( $workflow, StepRegistry::get( 'order_created' ), [ 'order_id' => $order->get_id() ] );
    as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $run_id ], ActionSchedulerQueue::GROUP );
    RunStore::claim( $run_id );
    RunStore::save( $run_id, [ 'position' => [ 'queue' => [ 'note' ] ] ] );
    RunStepStore::start( $run_id, 'note', [] );
    $before = $notes( wc_get_order( $order->get_id() ) );
    $ok( null === Runtime::worker()->run( $run_id ), 'recovery: a normal job can\'t take a running run' );
    $ok( 'failed' === Runtime::worker()->run( $run_id, true ), 'recovery: the recovery job takes it (MySQL claim from running)' );
    $run = RunStore::find( $run_id );
    $ok( 'Interrupted.' === $run['error'] && $notes( wc_get_order( $order->get_id() ) ) === $before, 'recovery: interrupted step failed, not run again' );

    // 7. A waiting run is only taken once its time has come.
    $run_id = Runtime::dispatcher()->start_run( $workflow, StepRegistry::get( 'order_created' ), [ 'order_id' => $order->get_id() ] );
    as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $run_id ], ActionSchedulerQueue::GROUP );
    RunStore::save(
        $run_id,
        [
            'status'     => RunStore::STATUS_WAITING,
            'wait_until' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
        ]
    );
    $ok( ! RunStore::claim( $run_id ), 'waiting: not taken before wait_until' );
    RunStore::save( $run_id, [ 'wait_until' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ] );
    $ok( RunStore::claim( $run_id ), 'waiting: taken once wait_until has passed' );

    // 8. One job per run in the real Action Scheduler (a run id no run has).
    $queue = new ActionSchedulerQueue();
    $ghost = 999999999;
    $queue->enqueue( $ghost, time() + HOUR_IN_SECONDS );
    $queue->enqueue( $ghost );
    $queue->recover( $ghost );
    $queue->recover( $ghost );
    $count = fn( string $hook ) => count(
        as_get_scheduled_actions(
            [
                'hook'   => $hook,
                'args'   => [ $ghost ],
                'group'  => ActionSchedulerQueue::GROUP,
                'status' => 'pending',
            ],
            'ids'
        )
    );
    $ok( 1 === $count( ActionSchedulerQueue::HOOK ) && 1 === $count( ActionSchedulerQueue::RECOVER_HOOK ), 'queue: one pending run job and one recovery job per run' );
    as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $ghost ], ActionSchedulerQueue::GROUP );
    as_unschedule_all_actions( ActionSchedulerQueue::RECOVER_HOOK, [ $ghost ], ActionSchedulerQueue::GROUP );
} catch ( Throwable $e ) {
    $ok( false, 'unexpected error: ' . $e->getMessage() );
} finally {
    // Cleanup: workflows, order, runs by root (child runs too), their jobs.
    // Every temporary workflow, also any created before an error stopped the script.
    $ok( ! yaymatik_check_remove_workflows( 'Check runs:' ), 'cleanup: temporary workflows deleted' );
    if ( isset( $order ) && $order ) {
        $order->delete( true );
    }
    $left = yaymatik_check_remove_runs( $since );
    $ok( 0 === $left['runs'] && 0 === $left['jobs'], 'cleanup: no runs or jobs left' );
}//end try

echo $fails ? "{$fails} failed\n" : "All passed\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
if ( $fails ) {
    WP_CLI::halt( 1 );
}
