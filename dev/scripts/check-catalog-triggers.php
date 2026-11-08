<?php
/**
 * The catalog's new triggers, through the real Dispatcher and Worker, on this order store.
 * Required by check-catalog.php, whose helpers and cleanup it uses.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use YayMatik\Workflow\Core\Definition\GraphSchema;
use YayMatik\Workflow\Core\Email\EmailPreview;
use YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Core\Runtime\Runtime;
use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\WorkflowStore;

/** An active workflow from this graph; its id. */
$activate_graph = function ( array $graph ) use ( &$flows ): int {
    $created = WorkflowStore::create( 'check-catalog', $graph );
    if ( ! is_array( $created ) ) {
        WP_CLI::error( 'Could not create a temporary workflow.' );
    }
    $flows[] = (int) $created['id'];
    if ( true !== WorkflowStore::activate( (int) $created['id'] ) ) {
        WP_CLI::error( 'Could not activate a temporary workflow.' );
    }
    return (int) $created['id'];
};
/** Trigger → Add order note. */
$active_flow = fn( string $trigger, array $config = [] ) => $activate_graph( $graph( $trigger, $config ) );
/**
 * A graph: this trigger, then each step in turn.
 *
 * @param array[] $steps [ id, step id, config ]
 */
$chain = function ( string $trigger, array $trigger_config, array $steps ): array {
    $nodes = [];
    $edges = [];
    $last  = 'trigger';
    foreach ( array_merge( [ [ 'trigger', $trigger, $trigger_config ] ], $steps ) as $y => $step ) {
        list( $id, $block, $config ) = $step;
        $nodes[]                     = [
            'id'       => $id,
            'kind'     => 'trigger' === $id ? 'trigger' : 'action',
            'position' => [
                'x' => 0,
                'y' => 100 * $y,
            ],
            'data'     => [
                'block'  => $block,
                'config' => $config,
            ],
        ];
        if ( 'trigger' !== $id ) {
            $edges[] = [
                'id'     => "e-{$id}",
                'source' => $last,
                'target' => $id,
            ];
        }
        $last = $id;
    }
    return GraphSchema::prepare(
        [
            'nodes' => $nodes,
            'edges' => $edges,
        ]
    );
};

$last_run   = fn() => (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . RunStore::table() );
$runs_after = fn( int $flow_id, int $after ) => array_map(
    'intval',
    $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . RunStore::table() . ' WHERE workflow_id = %d AND id > %d ORDER BY id', $flow_id, $after ) )
);
/** Runs started by `$event` for each workflow, by id, after the end-of-request flush. */
$started = function ( array $flow_ids, callable $event ) use ( $last_run, $runs_after ): array {
    $before = $last_run();
    $event();
    Runtime::dispatcher()->flush();
    $counts = [];
    foreach ( $flow_ids as $flow_id ) {
        $counts[ $flow_id ] = count( $runs_after( $flow_id, $before ) );
    }
    return $counts;
};
/** Runs these runs now through the Worker, as their Action Scheduler job would, then flushes their events. */
$work = function ( array $run_ids ) {
    foreach ( $run_ids as $run_id ) {
        as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $run_id ], ActionSchedulerQueue::GROUP );
        Runtime::worker()->run( $run_id );
    }
    Runtime::dispatcher()->flush();
};

// Order paid.
$paid_flow = $active_flow( 'order_paid' );
$paid      = fn( callable $event ) => $started( [ $paid_flow ], $event )[ $paid_flow ];
$move      = fn( int $order_id, string $to ) => fn() => wc_get_order( $order_id )->update_status( $to );

$pending = $order();
$ok( 1 === $paid( $move( $pending, 'processing' ) ), 'paid: pending → processing starts it once' );
$ok( 0 === $paid( $move( $pending, 'completed' ) ), 'paid: processing → completed doesn\'t' );
$ok( 0 === $paid( $move( $pending, 'on-hold' ) ) && 0 === $paid( $move( $pending, 'processing' ) ), 'paid: unpaid and paid again doesn\'t start it again' );

$ok(
    0 === $paid(
        function () use ( &$orders, $domain ) {
            $created = wc_create_order( [ 'status' => 'processing' ] );
            $created->set_billing_email( 'created@' . $domain );
            $created->save();
            $orders[] = $created->get_id();
        }
    ),
    'paid: wc_create_order() with a paid status (saved paid at once) doesn\'t'
);
$created = end( $orders );
$ok( '' !== wc_get_order( $created )->get_meta( '_yaymatik_order_paid' ), 'paid: that order is marked as started, so a later unpaid → paid change doesn\'t start it' );
$later = $order();
$ok( 1 === $paid( fn() => wc_get_order( $later )->payment_complete( 'txn-check' ) ), 'paid: created pending, then payment_complete() starts it' );
$drafts = [
    'auto-draft'     => 'admin order (auto-draft first)',
    'checkout-draft' => 'checkout draft',
];
foreach ( $drafts as $draft => $what ) {
    $draft_id = $order( [ 'status' => $draft ] );
    $ok( 1 === $paid( $move( $draft_id, 'processing' ) ), "paid: {$what} saved to processing starts it" );
}
$on_hold = fn( array $statuses ) => array_merge( $statuses, [ 'on-hold' ] );
add_filter( 'woocommerce_order_is_paid_statuses', $on_hold );
$ok( 1 === $paid( $move( $order(), 'on-hold' ) ), 'paid: a status the paid-status filter adds counts' );
remove_filter( 'woocommerce_order_is_paid_statuses', $on_hold );
WorkflowStore::deactivate( $paid_flow );

// Order note added.
$customer_notes = $active_flow( 'order_note_added', [ 'note_type' => 'customer' ] );
$shipped_notes  = $active_flow( 'order_note_added', [ 'contains' => 'Shipped' ] );
$skip_on        = $active_flow( 'order_note_added' );
$skip_off       = $active_flow( 'order_note_added', [ 'skip_yaymatik' => false ] );
$note_flows     = [ $customer_notes, $shipped_notes, $skip_on, $skip_off ];
$noted          = $order();

$counts = $started( $note_flows, fn() => wc_get_order( $noted )->add_order_note( 'Packed' ) );
$ok( [ 0, 0, 1, 1 ] === array_values( $counts ), 'note: a private note starts only the "any note" workflows' );
$counts = $started( $note_flows, fn() => wc_get_order( $noted )->add_order_note( 'Your order was SHIPPED today', 1 ) );
$ok( [ 1, 1, 1, 1 ] === array_values( $counts ), 'note: a customer note with "shipped" in another case starts all four' );

// A run changes the status: WooCommerce's status note is added while the run runs.
$status_flow = $activate_graph( $chain( 'order_paid', [], [ [ 'status', 'change_order_status', [ 'status' => 'completed' ] ] ] ) );
$by_run      = $order();
$before      = $last_run();
wc_get_order( $by_run )->update_status( 'processing' );
Runtime::dispatcher()->flush();
$status_runs = $runs_after( $status_flow, $before );
$before      = $last_run();
$work( $status_runs );
$ok( 'completed' === wc_get_order( $by_run )->get_status(), 'note: the paid run changed the status' );
$ok( ! $runs_after( $skip_on, $before ), 'note: a status note added during a run doesn\'t start a workflow that skips them' );
$loop_runs = $runs_after( $skip_off, $before );
$ok( 1 === count( $loop_runs ), 'note: with Skip off, the run\'s status note starts it' );
$before = $last_run();
$work( $loop_runs );
$ok( ! $runs_after( $skip_off, $before ), 'note: its own run\'s note doesn\'t start it again (loop guard)' );
$ok( ! $runs_after( $skip_on, $before ), 'note: a note an Add order note step adds doesn\'t start a workflow that skips them' );
foreach ( array_merge( $note_flows, [ $status_flow ] ) as $flow_id ) {
    WorkflowStore::deactivate( $flow_id );
}

// Order refunded.
$plain_price = fn( float $amount ) => html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
$refund_note = [
    'type' => 'private',
    'note' => 'refund {{ refund.amount }} full {{ refund.is_full }}',
];
$wait_hour   = [
    'delay' => [
        'value' => 1,
        'unit'  => 'hours',
    ],
];
$refund_flow = $activate_graph( $chain( 'order_refunded', [], [ [ 'note', 'add_order_note', $refund_note ] ] ) );
$wait_flow   = $activate_graph( $chain( 'order_refunded', [], [ [ 'wait', 'wait', $wait_hour ], [ 'late', 'add_order_note', $refund_note ] ] ) );
$refunded    = $order( [ 'total' => '50' ] );
$refund      = function ( string $amount ) use ( $refunded ) {
    $made = wc_create_refund(
        [
            'order_id' => $refunded,
            'amount'   => $amount,
            'reason'   => 'check-catalog',
        ]
    );
    if ( is_wp_error( $made ) ) {
        WP_CLI::error( 'Could not refund: ' . $made->get_error_message() );
    }
    Runtime::dispatcher()->flush();
    return $made;
};

$before       = $last_run();
$first_refund = $refund( '20' );
$partial_runs = $runs_after( $refund_flow, $before );
$waiting_runs = $runs_after( $wait_flow, $before );
$before       = $last_run();
$refund( '30' );
$full_runs = $runs_after( $refund_flow, $before );
$subjects  = array_unique( array_map( fn( int $run_id ) => RunStore::find( $run_id )['subject'], array_merge( $partial_runs, $full_runs ) ) );
$ok( 1 === count( $partial_runs ) && 1 === count( $full_runs ), 'refunded: a partial refund, then the rest, start one run each' );
$ok( [ "order:{$refunded}" ] === array_values( $subjects ), 'refunded: both runs are about the order' );

$work( array_merge( $partial_runs, $full_runs ) );
$notes = wp_list_pluck( wc_get_order_notes( [ 'order_id' => $refunded ] ), 'content' );
$ok( in_array( 'refund ' . $plain_price( 20 ) . ' full No', $notes, true ), 'refunded: the first refund reads Full refund No, after the second landed' );
$ok( in_array( 'refund ' . $plain_price( 30 ) . ' full Yes', $notes, true ), 'refunded: the second reads Full refund Yes; refund tags render' );

$work( $waiting_runs );
$ok( 'waiting' === RunStore::find( $waiting_runs[0] )['status'], 'refunded: a run waits before reading the refund' );
$first_refund->delete( true );
RunStore::save( $waiting_runs[0], [ 'wait_until' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
$work( $waiting_runs );
$late = array_values( array_filter( RunStepStore::for_run( $waiting_runs[0] ), fn( array $step ) => 'late' === $step['step_id'] ) )[0] ?? [];
$ok( 'skipped' === ( $late['status'] ?? '' ) && 'Refund no longer exists.' === ( $late['error'] ?? '' ), 'refunded: the refund deleted during the Wait → the step is skipped, Refund no longer exists.' );

$mail_graph = $chain(
    'order_refunded',
    [],
    [
        [
            'mail',
            'send_email',
            [
                'to'      => '{{ customer.email }}',
                'subject' => 'Refund',
                'heading' => 'Refund',
                'message' => 'Refund {{ refund.amount }}: {{ refund.reason }}',
            ],
        ],
    ]
);
$shown      = EmailPreview::render( $mail_graph, 'mail', $mail_graph['nodes'][1]['data']['config'], $refunded );
$ok( is_array( $shown ) && $shown['sample'] && false !== strpos( $shown['html'], 'Damaged in transit' ), 'refunded: Preview on a real order shows the sample refund, with the Sample badge' );
foreach ( [ $refund_flow, $wait_flow ] as $flow_id ) {
    WorkflowStore::deactivate( $flow_id );
}
