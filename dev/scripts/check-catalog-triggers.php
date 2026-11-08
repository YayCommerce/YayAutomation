<?php
/**
 * The catalog's new triggers, through the real Dispatcher and Worker, on this order store.
 * Required by check-catalog.php, whose helpers and cleanup it uses.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

use YayMatik\Engine\Privacy\PersonalData;
use YayMatik\Workflow\Core\Data\CustomerStats;
use YayMatik\Workflow\Core\Definition\GraphSchema;
use YayMatik\Workflow\Core\Email\EmailPreview;
use YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Core\Runtime\Runtime;
use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\TriggerClaimStore;
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
        $new                = $runs_after( $flow_id, $before );
        $counts[ $flow_id ] = count( $new );
        // Their jobs go at once: the site's own Action Scheduler runner could take a run before `$work` does, and send its mail uncaught.
        foreach ( $new as $run_id ) {
            as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $run_id ], ActionSchedulerQueue::GROUP );
        }
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

// Milestones.
$claims_table = $wpdb->prefix . 'yaymatik_trigger_claims';
$new_user     = function ( string $name ) use ( &$users, $domain ): array {
    $email   = "{$name}@{$domain}";
    $user_id = wp_create_user( $name . '_' . wp_rand( 100000, 999999 ), wp_generate_password(), $email );
    if ( ! is_int( $user_id ) ) {
        WP_CLI::error( 'Could not create a temporary user.' );
    }
    $users[] = $user_id;
    return [ $user_id, $email ];
};
/** A new order for this person, then paid; its id. */
$pay       = function ( int $user_id, string $email, string $total ) use ( $order ): int {
    $paid_id = $order(
        [
            'customer_id'   => $user_id,
            'billing_email' => $email,
            'total'         => $total,
        ]
    );
    wc_get_order( $paid_id )->update_status( 'processing' );
    Runtime::dispatcher()->flush();
    return $paid_id;
};
$runs_of   = fn( int $flow_id ) => $runs_after( $flow_id, 0 );
$claimed   = fn( int $flow_id, string $key ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$claims_table} WHERE workflow_id = %d AND subject = %s", $flow_id, $key ) );
$count_two = $active_flow( 'customer_order_count_reaches', [ 'count' => 2 ] );
$also_two  = $active_flow( 'customer_order_count_reaches', [ 'count' => 2 ] );
$spend_100 = $active_flow( 'customer_total_spend_reaches', [ 'amount' => 100 ] );

list( $buyer, $buyer_email ) = $new_user( 'milestone' );
$pay( $buyer, $buyer_email, '60' );
$ok( ! $runs_of( $count_two ) && ! $runs_of( $spend_100 ), 'milestones: a first paid order of 60 reaches neither 2 orders nor 100' );
$second = $pay( $buyer, $buyer_email, '50' );
$ok( 1 === count( $runs_of( $count_two ) ) && 1 === count( $runs_of( $spend_100 ) ), 'milestones: the second crosses 2 orders and 100 spent: one run each' );
$ok( 1 === count( $runs_of( $also_two ) ), 'milestones: two workflows on the same number start one run each' );
$ok( "order:{$second}" === RunStore::find( $runs_of( $count_two )[0] )['subject'] && 1 === $claimed( $count_two, "user:{$buyer}" ), 'milestones: the run is about the order, the claim about the account' );
$pay( $buyer, $buyer_email, '10' );
$ok( 1 === count( $runs_of( $count_two ) ), 'milestones: the third paid order doesn\'t start it again' );

$count_five = $active_flow( 'customer_order_count_reaches', [ 'count' => 5 ] );
$pay( $buyer, $buyer_email, '10' );
$ok( ! $runs_of( $count_five ), 'milestones: activated with 3 orders of history, the 4th doesn\'t start "5"' );
$pay( $buyer, $buyer_email, '10' );
$ok( 1 === count( $runs_of( $count_five ) ), 'milestones: the 5th does' );
WorkflowStore::deactivate( $count_five );

// Spent: 60 + 50 + 10 + 10 + 10 = 140. A refund of 50 → 90, below 100; a new order of 30 crosses 100 again.
wc_create_refund(
    [
        'order_id' => $second,
        'amount'   => '50',
        'reason'   => 'check-catalog',
    ]
);
Runtime::dispatcher()->flush();
$ok( CustomerStats::totals( $buyer, $buyer_email )['spent'] < 100, 'milestones: refunds take the buyer below 100 again' );
$pay( $buyer, $buyer_email, '30' );
$ok( 1 === count( $runs_of( $spend_100 ) ), 'milestones: crossing 100 again after a refund doesn\'t start it twice' );

$guest_email = "Guest.Milestone@{$domain}";
$guest_key   = TriggerClaimStore::email_key( $guest_email );
$pay( 0, $guest_email, '20' );
$guest_order = $pay( 0, strtolower( $guest_email ), '20' );
$ok( 1 === count( array_filter( $runs_of( $count_two ), fn( int $run_id ) => "order:{$guest_order}" === RunStore::find( $run_id )['subject'] ) ), 'milestones: a guest\'s second paid order starts it, counted by billing email in any case' );
$ok( 1 === $claimed( $count_two, $guest_key ), 'milestones: the guest\'s claim is the hash, the same for both cases' );

list( $mixed, $mixed_email ) = $new_user( 'mixed' );
$pay( $mixed, $mixed_email, '20' );
$guest_paid = $pay( 0, $mixed_email, '20' );
$ok( 1 === count( array_filter( $runs_of( $also_two ), fn( int $run_id ) => "order:{$guest_paid}" === RunStore::find( $run_id )['subject'] ) ), 'milestones: an account order and a guest order with the same email count as one person' );

$flow_ids  = implode( ',', array_map( 'intval', [ $count_two, $also_two, $spend_100 ] ) );
$at_claims = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$claims_table} WHERE workflow_id IN ({$flow_ids}) AND subject LIKE '%@%'" );
$in_scope  = array_filter(
    $wpdb->get_col( 'SELECT scope FROM ' . RunStore::table() . " WHERE workflow_id IN ({$flow_ids})" ),
    fn( $scope ) => false !== strpos( (string) ( json_decode( (string) $scope, true )['trigger']['claim_key'] ?? '' ), '@' )
);
$ok( 0 === $at_claims && ! $in_scope, 'milestones: no plain email in trigger_claims or in a run\'s claim_key' );

list( $off, $off_email ) = $new_user( 'turned-off' );
$pay( $off, $off_email, '20' );
$pay( $off, $off_email, '20' );
$off_run = array_values( array_filter( $runs_of( $also_two ), fn( int $run_id ) => 'queued' === RunStore::find( $run_id )['status'] && "user:{$off}" === ( RunStore::find( $run_id )['scope']['trigger']['claim_key'] ?? '' ) ) );
WorkflowStore::deactivate( $also_two );
$work( $off_run );
$ok( 1 === count( $off_run ) && 'cancelled' === RunStore::find( $off_run[0] )['status'] && 0 === $claimed( $also_two, "user:{$off}" ), 'milestones: turned off before the first step, the run is cancelled and the person\'s claim released' );

// Erase waits while a run about the person is in progress, as for carts: finish them first.
$work( array_values( array_filter( array_merge( $runs_of( $count_two ), $runs_of( $also_two ) ), fn( int $run_id ) => 'queued' === RunStore::find( $run_id )['status'] ) ) );
$privacy  = PersonalData::get_instance();
$exported = array_column( $privacy->export( strtolower( $guest_email ) )['data'], 'item_id' );
$ok( TriggerClaimStore::find_keys( $guest_email ) && in_array( 'yaymatik-milestones', $exported, true ), 'milestones: find_keys finds the hashed key; the export lists Customer milestones' );
$privacy->erase( strtolower( $guest_email ) );
$ok( ! TriggerClaimStore::find_keys( $guest_email ), 'milestones: erasing the email deletes its claims' );
$guest_scopes = array_map( fn( int $run_id ) => wp_json_encode( RunStore::find( $run_id )['scope'] ), $runs_of( $count_two ) );
$ok( ! array_filter( $guest_scopes, fn( $scope ) => false !== strpos( (string) $scope, $guest_key ) ), 'milestones: erasing removes the email\'s claim key from its runs too' );
$privacy->erase( $buyer_email );
$ok( 1 === $claimed( $count_two, "user:{$buyer}" ), 'milestones: erasing keeps user: claims' );
foreach ( [ $count_two, $spend_100 ] as $flow_id ) {
    WorkflowStore::deactivate( $flow_id );
}

// Review posted. Its emails go nowhere: wp_mail is caught here.
$mails   = [];
$catch   = function ( $sent, array $atts ) use ( &$mails ) {
    $mails[] = $atts;
    return true;
};
$product = new WC_Product_Simple();
$product->set_name( 'check-catalog mug' );
$product->set_status( 'publish' );
$posts[]                           = $product->save();
$review_on                         = function ( array $fields, array $meta = [] ) use ( $product, $domain ): int {
    $review_id = (int) wp_insert_comment(
        $fields + [
            'comment_post_ID'      => $product->get_id(),
            'comment_type'         => 'review',
            'comment_content'      => 'check-catalog review',
            'comment_author'       => 'Reviewer',
            'comment_author_email' => "other@{$domain}",
            'comment_approved'     => 1,
        ]
    );
    // WooCommerce saves these after `wp_insert_comment`, in `comment_post`.
    foreach ( $meta as $key => $value ) {
        add_comment_meta( $review_id, $key, $value );
    }
    return $review_id;
};
$review_mail                       = [
    'recipients' => 'staff@' . $domain,
    'subject'    => 'rating {{ review.rating }} verified {{ review.is_verified }} name [{{ customer.first_name }}] role [{{ customer.role }}] product {{ review.product_name }}',
    'message'    => 'check-catalog',
];
$review_flow                       = $activate_graph( $chain( 'review_posted', [], [ [ 'mail', 'send_internal_email', $review_mail ] ] ) );
$review_wait                       = $activate_graph( $chain( 'review_posted', [], [ [ 'wait', 'wait', $wait_hour ], [ 'mail', 'send_internal_email', $review_mail ] ] ) );
$reviewed                          = fn( callable $event ) => $started( [ $review_flow ], $event )[ $review_flow ];
$guest_email                       = "reviewer@{$domain}";
list( $reviewer, $reviewer_email ) = $new_user( 'reviewer' );
wp_update_user(
    [
        'ID'         => $reviewer,
        'first_name' => 'Ada',
    ]
);

$held = 0;
$ok(
    0 === $reviewed(
        function () use ( $review_on, &$held, $guest_email ) {
            $held = $review_on(
                [
                    'comment_approved'     => 0,
                    'comment_author_email' => $guest_email,
                ]
            );
        }
    ),
    'review: held for moderation doesn\'t start it'
);
$ok( 0 === $reviewed( fn() => $review_on( [ 'comment_approved' => 'spam' ] ) ), 'review: spam doesn\'t' );
$ok(
    1 === $reviewed(
        function () use ( $held ) {
            wp_set_comment_status( $held, 'approve' );
            add_comment_meta( $held, 'rating', 2 );
            add_comment_meta( $held, 'verified', 1 );
        }
    ),
    'review: approved later starts it once'
);
$ok(
    0 === $reviewed(
        function () use ( $held ) {
            wp_set_comment_status( $held, 'hold' );
            wp_set_comment_status( $held, 'approve' );
        }
    ),
    'review: unapproved and approved again doesn\'t start it again'
);
$ok( 0 === $reviewed( fn() => $review_on( [ 'comment_parent' => $held ] ) ), 'review: a reply doesn\'t' );
$ok( 0 === $reviewed( fn() => $review_on( [ 'comment_type' => 'comment' ] ) ), 'review: a plain comment doesn\'t' );
$admin_review = 0;
$ok(
    1 === $reviewed(
        function () use ( $review_on, &$admin_review, $reviewer, $reviewer_email ) {
            $admin_review = $review_on(
                [
                    'user_id'              => $reviewer,
                    'comment_author_email' => $reviewer_email,
                ],
                [ 'rating' => 5 ]
            );
        }
    ),
    'review: a review added approved (an admin\'s, or an account\'s) starts it'
);
// The guest typed an email an account has: still only the email.
$account_email = $reviewer_email;
$typed         = $review_on( [ 'comment_author_email' => $account_email ], [ 'rating' => 3 ] );
Runtime::dispatcher()->flush();

add_filter( 'pre_wp_mail', $catch, 10, 2 );
$work( array_values( array_filter( $runs_of( $review_flow ), fn( int $run_id ) => 'queued' === RunStore::find( $run_id )['status'] ) ) );
remove_filter( 'pre_wp_mail', $catch );
$subjects     = array_column( $mails, 'subject' );
$product_name = $product->get_name();
$ok( in_array( "rating 2 verified Yes name [] role [] product {$product_name}", $subjects, true ), 'review: rating and verified read at shutdown; a guest has no name or role' );
$ok( (bool) preg_grep( '/^rating 5 verified No name \[Ada\] role \[[^\]]+\] product /', $subjects ), 'review: a review with a user_id reads that account (name and role)' );
$ok( in_array( "rating 3 verified No name [] role [] product {$product_name}", $subjects, true ), 'review: a guest who typed an account\'s email is still only that email' );
$review_run = RunStore::find( $runs_of( $review_flow )[0] );
$ok( "review:{$held}" === $review_run['subject'], 'review: the run is about the review' );

$exported = array_column( PersonalData::get_instance()->export( $guest_email )['data'], 'item_id' );
$ok( in_array( 'yaymatik-run-' . $review_run['id'], $exported, true ), 'review: the export finds the review run by the author email' );
$by_account = array_column( PersonalData::get_instance()->export( $reviewer_email )['data'], 'item_id' );
$admin_run  = array_values( array_filter( $runs_of( $review_flow ), fn( int $run_id ) => "review:{$admin_review}" === RunStore::find( $run_id )['subject'] ) );
$ok( $admin_run && in_array( 'yaymatik-run-' . $admin_run[0], $by_account, true ), 'review: and by the account that has the email' );

$waiting = array_values( array_filter( $runs_of( $review_wait ), fn( int $run_id ) => "review:{$typed}" === RunStore::find( $run_id )['subject'] ) );
$work( $waiting );
$ok( $waiting && 'waiting' === RunStore::find( $waiting[0] )['status'], 'review: a run waits before its email' );
wp_delete_comment( $typed, true );
RunStore::save( $waiting[0], [ 'wait_until' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
add_filter( 'pre_wp_mail', $catch, 10, 2 );
$work( $waiting );
remove_filter( 'pre_wp_mail', $catch );
$late = array_values( array_filter( RunStepStore::for_run( $waiting[0] ), fn( array $step ) => 'mail' === $step['step_id'] ) )[0] ?? [];
$ok( 'skipped' === ( $late['status'] ?? '' ) && false !== strpos( (string) ( $late['error'] ?? '' ), 'no longer exists' ), 'review: deleted during the Wait → its email is skipped (' . ( $late['error'] ?? '' ) . ')' );

$review_preview = EmailPreview::render( $chain( 'review_posted', [], [ [ 'mail', 'send_internal_email', $review_mail ] ] ), 'mail', $review_mail );
$ok( is_array( $review_preview ) && $review_preview['sample'] && false !== strpos( (string) $review_preview['subject'], 'rating 5' ), 'review: Preview shows the sample review, with the Sample badge' );
foreach ( [ $review_flow, $review_wait ] as $flow_id ) {
    WorkflowStore::deactivate( $flow_id );
}
