<?php
/**
 * E2E for runs, through real WooCommerce events: the Dispatcher queues runs, each run's job is then
 * run right away by the Worker (its Action Scheduler job is removed); a Wait's job is run by Action
 * Scheduler's runner. Prints PASS / FAIL lines.
 * Cleans up its order, workflows, runs (by root run, so child runs too) and jobs. Run through
 * check-runs.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

require_once __DIR__ . '/check-run.php';

use YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Core\Runtime\RepositoryWorkflows;
use YayMatik\Workflow\Core\Runtime\Runtime;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;

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
    $workflow = YayMatik\Workflow\Core\Storage\WorkflowStore::find( $created_wf );
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

    // 9. Wait 1 minute → note: the run waits, then its own Action Scheduler job resumes it.
    $wait_graph = fn( string $note ) => $graph(
        'order_status_changed',
        [ 'to' => [ 'cancelled' ] ],
        [
            [
                'wait',
                'wait',
                [
                    'delay' => [
                        'value' => 1,
                        'unit'  => 'minutes',
                    ],
                ],
            ],
            [ 'note', 'add_order_note', [ 'note' => $note ] ],
        ]
    );
    $wait_wf    = $create( 'wait', $wait_graph( 'Check-runs: after the wait' ) );
    $job_of     = function ( int $run_id ) {
        $ids = as_get_scheduled_actions(
            [
                'hook'   => ActionSchedulerQueue::HOOK,
                'args'   => [ $run_id ],
                'group'  => ActionSchedulerQueue::GROUP,
                'status' => 'pending',
            ],
            'ids'
        );
        return $ids ? (int) reset( $ids ) : 0;
    };
    /** A run of the wait workflow, left waiting by its first slice. */
    $waiting_run = function () use ( $order, $dispatch, $run_job, &$wait_wf ) {
        $order = wc_get_order( $order->get_id() );
        $order->update_status( 'pending' );
        $order->update_status( 'cancelled' );
        $runs = $dispatch( $wait_wf );
        return $runs ? $run_job( $runs[0] ) : null;
    };
    $run         = $waiting_run();
    $until       = $run && $run['wait_until'] ? strtotime( $run['wait_until'] . ' UTC' ) : 0;
    $job         = $run ? $job_of( (int) $run['id'] ) : 0;
    $ok( $run && 'waiting' === $run['status'] && abs( $until - ( time() + 60 ) ) <= 5, 'wait: run waits about 1 minute' );
    $ok( $job && abs( ActionScheduler::store()->fetch_action( (string) $job )->get_schedule()->get_date()->getTimestamp() - $until ) <= 1, 'wait: its job is scheduled at wait_until' );
    // The minute "passes": the real job is run by Action Scheduler's runner.
    RunStore::save( (int) $run['id'], [ 'wait_until' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ] );
    ActionScheduler::runner()->process_action( $job );
    $run   = RunStore::find( (int) $run['id'] );
    $steps = array_column( RunStepStore::for_run( (int) $run['id'] ), 'status', 'step_id' );
    $ok(
        'completed' === $run['status'] && [
            'wait' => 'completed',
            'note' => 'completed',
        ] === $steps,
        'wait: the job resumed the run; wait and note completed'
    );
    $ok( in_array( 'Check-runs: after the wait', $notes( wc_get_order( $order->get_id() ) ), true ), 'wait: the step after it ran' );

    // Runs REST and stats, on the runs so far.
    $request = new WP_REST_Request( 'GET', "/yaymatik/v1/workflows/{$wait_wf}/runs" );
    $request->set_query_params( [ 'status' => 'completed' ] );
    $page   = rest_do_request( $request )->get_data();
    $item   = $page['items'][0] ?? [];
    $detail = $item ? $call( 'GET', 'runs/' . $item['id'] ) : [];
    $ok( 1 === ( $page['total'] ?? 0 ) && 'Order #' . $order->get_order_number() === ( $item['subjectLabel'] ?? '' ) && $order->get_edit_order_url() === ( $item['subjectUrl'] ?? '' ) && 2 === ( $item['stepsRun'] ?? 0 ), 'runs REST: filter, total, subject label and URL, steps run' );
    $ok( [ 'wait', 'add_order_note' ] === array_column( $detail['steps'] ?? [], 'stepType' ), 'runs REST: run detail names each step type' );
    $wf = $call( 'GET', "workflows/{$wait_wf}" );
    $ok( 1 === $wf['runs30d'] && null !== $wf['lastRunAt'] && 0 === $wf['waitingRuns'], 'runs REST: workflow stats' );

    // 10. Deactivate cancels waiting runs and says how many; their leftover job does nothing.
    $run    = $waiting_run();
    $job    = $run ? $job_of( (int) $run['id'] ) : 0;
    $listed = $call( 'GET', 'workflows' );
    $ok( 1 === ( array_column( $listed, 'waitingRuns', 'id' )[ (string) $wait_wf ] ?? 0 ), 'deactivate: the list counts the waiting run' );
    $off   = $call( 'POST', "workflows/{$wait_wf}/deactivate" );
    $after = RunStore::find( (int) $run['id'] );
    $step  = RunStepStore::for_run( (int) $run['id'] )[0] ?? [];
    $ok( 1 === ( $off['cancelledRuns'] ?? null ) && 0 === $off['waitingRuns'], 'deactivate: cancelledRuns 1, none waiting' );
    $ok( 'cancelled' === $after['status'] && 'The workflow was deactivated.' === $after['error'] && null === $after['wait_until'] && null !== $after['finished_at'], 'deactivate: run cancelled with the reason, no wait_until' );
    $ok( 'cancelled' === ( $step['status'] ?? '' ) && null !== $step['finished_at'], 'deactivate: its waiting step row cancelled' );
    $before = $notes( wc_get_order( $order->get_id() ) );
    if ( $job ) {
        ActionScheduler::runner()->process_action( $job );
    }
    $ok( $job && 'cancelled' === RunStore::find( (int) $run['id'] )['status'] && $notes( wc_get_order( $order->get_id() ) ) === $before, 'deactivate: the leftover job does nothing' );

    // 11. Apply cancels waiting runs too.
    $call( 'POST', "workflows/{$wait_wf}/activate" );
    $run = $waiting_run();
    $call( 'PUT', "workflows/{$wait_wf}", [ 'graph' => $wait_graph( 'Check-runs: v2' ) ] );
    $applied = $call( 'POST', "workflows/{$wait_wf}/apply" );
    $after   = RunStore::find( (int) $run['id'] );
    $ok( 1 === ( $applied['cancelledRuns'] ?? null ) && 'cancelled' === $after['status'] && 'The workflow was updated.' === $after['error'], 'apply: waiting run cancelled, cancelledRuns 1' );

    // 12. The rate-limit pause cancels waiting runs.
    $run = $waiting_run();
    ( new RepositoryWorkflows() )->deactivate( $wait_wf );
    $ok( 'cancelled' === RunStore::find( (int) $run['id'] )['status'], 'pause: waiting run cancelled' );

    // Another connection holds the workflow lock: the pause waits 10 s, logs and leaves it on.
    $call( 'POST', "workflows/{$wait_wf}/activate" );
    $holder = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
    $lock   = "{$wpdb->prefix}yaymatik_workflow_{$wait_wf}";
    $holder->get_var( $holder->prepare( 'SELECT GET_LOCK( %s, 0 )', $lock ) );
    $logged = [];
    $log    = function ( $message ) use ( &$logged ) {
        $logged[] = $message;
        return $message;
    };
    add_filter( 'woocommerce_logger_log_message', $log );
    ( new RepositoryWorkflows() )->deactivate( $wait_wf );
    remove_filter( 'woocommerce_logger_log_message', $log );
    $holder->get_var( $holder->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
    $ok( YayMatik\Workflow\Core\Storage\WorkflowStore::is_active( $wait_wf ) && preg_grep( "/Workflow {$wait_wf} not turned off: could not take its lock/", $logged ), 'pause: lock held → logged, still active' );

    // Already turned off by REST: the pause records no second version.
    $call( 'POST', "workflows/{$wait_wf}/deactivate" );
    $versions = fn() => count( YayMatik\Workflow\Core\Storage\WorkflowStore::versions( $wait_wf, 1 )['items'] );
    $before   = $versions();
    ( new RepositoryWorkflows() )->deactivate( $wait_wf );
    $ok( $versions() === $before, 'pause: already off, no new version' );

    // 13. Turned off mid-run: the step running finishes, the next one never starts.
    $two_wf   = $create(
        'two notes',
        $graph(
            'order_status_changed',
            [ 'to' => [ 'cancelled' ] ],
            [
                [ 'one', 'add_order_note', [ 'note' => 'Check-runs: one' ] ],
                [ 'two', 'add_order_note', [ 'note' => 'Check-runs: two' ] ],
            ]
        )
    );
    $turn_off = function ( $note_id ) use ( $two_wf ) {
        if ( false !== strpos( (string) get_comment_text( $note_id ), 'Check-runs: one' ) ) {
            YayMatik\Workflow\Core\Storage\WorkflowStore::deactivate( $two_wf );
        }
    };
    add_action( 'woocommerce_order_note_added', $turn_off );
    $order = wc_get_order( $order->get_id() );
    $order->update_status( 'pending' );
    $order->update_status( 'cancelled' );
    $runs = $dispatch( $two_wf );
    $run  = $runs ? $run_job( $runs[0] ) : null;
    remove_action( 'woocommerce_order_note_added', $turn_off );
    $all = $notes( wc_get_order( $order->get_id() ) );
    $ok( $run && 'cancelled' === $run['status'] && 'The workflow was deactivated.' === $run['error'], 'mid-run: run cancelled when turned off' );
    $ok( in_array( 'Check-runs: one', $all, true ) && ! in_array( 'Check-runs: two', $all, true ), 'mid-run: the next step never ran' );
    $ok( $run && [ 'one' => 'completed' ] === array_column( RunStepStore::for_run( (int) $run['id'] ), 'status', 'step_id' ), 'mid-run: only the first step has a row' );

    // 14. Health check: stuck runs and the cleanup job, found and fixed through REST. A fix also
    // queues any other stuck run of this site: that is what it is for.
    $health  = function ( string $id ) use ( $call ) {
        $checks = array_column( $call( 'GET', 'health/checks' )['checks'] ?? [], null, 'id' );
        return $checks[ $id ] ?? [];
    };
    $stuck   = function ( string $status ) use ( $created_wf, $order, $wpdb ) {
        $run_id = Runtime::dispatcher()->start_run( YayMatik\Workflow\Core\Storage\WorkflowStore::find( $created_wf ), StepRegistry::get( 'order_created' ), [ 'order_id' => $order->get_id() ] );
        as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $run_id ], ActionSchedulerQueue::GROUP );
        $wpdb->update(
            RunStore::table(),
            [
                'status'     => $status,
                'started_at' => gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ),
            ],
            [ 'id' => $run_id ]
        );
        return $run_id;
    };
    $has_job = fn( string $hook, int $run_id ) => as_has_scheduled_action( $hook, [ $run_id ], ActionSchedulerQueue::GROUP );
    $fix     = function ( string $check ) {
        $request = new WP_REST_Request( 'POST', '/yaymatik/v1/health/fix' );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( wp_json_encode( [ 'check' => $check ] ) );
        return rest_do_request( $request );
    };

    $run_id = $stuck( RunStore::STATUS_QUEUED );
    $check  = $health( 'runs_queued' );
    $ok( 'problem' === ( $check['status'] ?? '' ) && 'enqueue' === $check['fix'] && in_array( $run_id, $check['runIds'], true ), 'health: queued run with no job for 10 min → runs_queued problem' );
    $fixed = $fix( 'runs_queued' )->get_data();
    $after = array_column( $fixed['checks'] ?? [], null, 'id' )['runs_queued'] ?? [];
    $ok( ( $fixed['fixed'] ?? 0 ) >= 1 && $has_job( ActionSchedulerQueue::HOOK, $run_id ), 'health: fix queued a yaymatik_run job for it' );
    $ok( 'ok' === ( $after['status'] ?? '' ), 'health: runs_queued ok after the fix' );
    as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $run_id ], ActionSchedulerQueue::GROUP );

    $run_id = $stuck( RunStore::STATUS_RUNNING );
    $check  = $health( 'runs_running' );
    $ok( 'problem' === ( $check['status'] ?? '' ) && 'recover' === $check['fix'] && in_array( $run_id, $check['runIds'], true ), 'health: running run with no job → runs_running problem' );
    $fix( 'runs_running' );
    $ok( $has_job( ActionSchedulerQueue::RECOVER_HOOK, $run_id ) && 'ok' === ( $health( 'runs_running' )['status'] ?? '' ), 'health: fix queued a yaymatik_recover_run job; runs_running ok' );
    as_unschedule_all_actions( ActionSchedulerQueue::RECOVER_HOOK, [ $run_id ], ActionSchedulerQueue::GROUP );

    as_unschedule_all_actions( YayMatik\Workflow\Core\Runtime\Cleanup::HOOK, [], ActionSchedulerQueue::GROUP );
    $check = $health( 'cleanup_job' );
    $ok( 'problem' === ( $check['status'] ?? '' ) && 'schedule' === $check['fix'], 'health: cleanup job removed → cleanup_job problem' );
    $fix( 'cleanup_job' );
    $next = as_next_scheduled_action( YayMatik\Workflow\Core\Runtime\Cleanup::HOOK, [], ActionSchedulerQueue::GROUP );
    $ok( is_int( $next ) && $next > time() && 'ok' === ( $health( 'cleanup_job' )['status'] ?? '' ), 'health: fix scheduled it again; cleanup_job ok' );

    $late = $health( 'jobs_late' );
    $ok( in_array( $late['status'] ?? '', [ 'ok', 'info' ], true ) && null === $late['fix'] && 0 === strpos( (string) ( $late['guide'] ?? '' ), 'https://developer.wordpress.org/' ), 'health: jobs_late is ok or info (' . ( $late['count'] ?? '?' ) . ' late), no fix, guide link' );
    $ok( 400 === $fix( 'jobs_late' )->get_status() && 400 === $fix( 'nope' )->get_status(), 'health: fix of jobs_late or an unknown check → 400' );
    $ok( true === ( $call( 'GET', 'health' )['ok'] ?? null ), 'health: /health ping unchanged' );
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
