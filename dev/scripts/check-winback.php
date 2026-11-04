<?php
/**
 * Trigger claims on the real table, with workflow ids no real workflow has, plus temporary workflows.
 * The inactive sweep on real orders at a random `.test` domain, through the real Dispatcher. Runs stay queued, their jobs removed, except the
 * template runs, which go through the real Worker: their email goes to the local mail catcher, their coupons are removed.
 * Refuses to run while the site has an active Customer inactive workflow: it would start on these orders.
 * Cleanup also deletes the site's own old runs and carts, and claims of its deleted workflows.
 * Prints PASS / FAIL lines; removes its rows. Run through check-winback.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

use YayMatik\Workflow\Core\Email\EmailPreview;
use YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Core\Runtime\Cleanup;
use YayMatik\Workflow\Core\Runtime\HealthCheck;
use YayMatik\Workflow\Core\Runtime\InactiveSweep;
use YayMatik\Workflow\Core\Runtime\Runtime;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\Schema;
use YayMatik\Workflow\Core\Storage\TriggerClaimStore;
use YayMatik\Workflow\Core\Storage\UnsubscribeStore;
use YayMatik\Workflow\Core\Storage\WorkflowStore;

if ( ! function_exists( 'WC' ) ) {
    WP_CLI::error( 'WooCommerce is not active.' );
}

// WooCommerce's own order emails stay off.
foreach ( WC()->mailer()->get_emails() as $wc_email ) {
    add_filter( 'woocommerce_email_enabled_' . $wc_email->id, '__return_false' );
}

global $wpdb;
$claims  = Schema::table( Schema::TRIGGER_CLAIMS );
$ghost   = 999999;
$subject = 'order:' . wp_rand( 100000, 999999 );
$flows   = [];
$orders  = [];
$coupons = [];
$domain  = 'x' . wp_rand( 1000, 9999 ) . '.test';

$fails = 0;
$ok    = function ( bool $pass, string $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $fails += $pass ? 0 : 1;
};

$cleanup = function () use ( $wpdb, $claims, $ghost, $domain, &$flows, &$orders, &$coupons ) {
    foreach ( $coupons as $coupon_id ) {
        wp_delete_post( $coupon_id, true );
    }
    $unsubscribes = Schema::table( Schema::UNSUBSCRIBES );
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$unsubscribes} WHERE email LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) );
    $runs_table = RunStore::table();
    foreach ( $flows as $flow_id ) {
        WorkflowStore::deactivate( $flow_id );
        WorkflowStore::delete( $flow_id );
        $wpdb->delete( $claims, [ 'workflow_id' => $flow_id ] );
        $run_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$runs_table} WHERE workflow_id = %d", $flow_id ) ) );
        foreach ( $run_ids as $run_id ) {
            as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $run_id ], ActionSchedulerQueue::GROUP );
        }
        if ( $run_ids ) {
            RunStore::delete_with_steps( $run_ids );
        }
    }
    foreach ( $orders as $order_id ) {
        $saved_order = wc_get_order( $order_id );
        if ( $saved_order ) {
            $saved_order->delete( true );
        }
    }
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$claims} WHERE workflow_id IN (%d, %d)", $ghost, $ghost + 1 ) );
};
register_shutdown_function( $cleanup );

// Claims.
$ok( '7' === get_option( 'yaymatik_db_version' ), 'schema at DB version 7' );
$ok( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $claims ) ) ) === $claims, 'trigger_claims table present' );
$ok( true === TriggerClaimStore::claim( $ghost, $subject ), 'first claim: true' );
$ok( false === TriggerClaimStore::claim( $ghost, $subject ), 'second claim: false' );
$ok( true === TriggerClaimStore::claim( $ghost + 1, $subject ), 'same subject on another workflow: true' );
$claimed_at = $wpdb->get_var( $wpdb->prepare( "SELECT claimed_at FROM {$claims} WHERE workflow_id = %d", $ghost ) );
$ok( abs( strtotime( $claimed_at . ' UTC' ) - time() ) < 60, 'claimed_at is GMT' );
TriggerClaimStore::release( $ghost, $subject );
$ok( true === TriggerClaimStore::claim( $ghost, $subject ), 'after release: true again' );

// Orphans: a deleted workflow's claims go, a live workflow's stay.
$created = WorkflowStore::create(
    'check-winback',
    [
        'nodes' => [],
        'edges' => [],
    ]
);
if ( ! is_array( $created ) ) {
    WP_CLI::error( 'Could not create the temporary workflow.' );
}
$live    = (int) $created['id'];
$flows[] = $live;
TriggerClaimStore::claim( $live, $subject );
$row = fn( int $workflow_id ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$claims} WHERE workflow_id = %d", $workflow_id ) );
Cleanup::run();
$ok( 0 === $row( $ghost ) && 0 === $row( $ghost + 1 ), 'cleanup: claims of a deleted workflow gone' );
$ok( 1 === $row( $live ), 'cleanup: claim of a live workflow stays' );

// The sweep. Workflows: Customer inactive → Add order note, active.
$own_flows = array_map( fn( array $workflow ) => (int) $workflow['id'], WorkflowStore::active_for_trigger( 'customer_inactive' ) );
if ( $own_flows ) {
    WP_CLI::error( 'Deactivate this site\'s Customer inactive workflows first: this check would start them on its orders. Ids: ' . implode( ', ', $own_flows ) );
}
$flow = function ( int $days ) use ( &$flows ): int {
    $graph   = [
        'nodes' => [
            [
                'id'       => 'trigger',
                'kind'     => 'trigger',
                'type'     => 'trigger',
                'position' => [
                    'x' => 0,
                    'y' => 0,
                ],
                'data'     => [
                    'block'  => 'customer_inactive',
                    'config' => [ 'days' => $days ],
                ],
            ],
            [
                'id'       => 'note',
                'kind'     => 'action',
                'type'     => 'action',
                'position' => [
                    'x' => 0,
                    'y' => 100,
                ],
                'data'     => [
                    'block'  => 'add_order_note',
                    'config' => [
                        'note' => 'check-winback',
                        'type' => 'private',
                    ],
                ],
            ],
        ],
        'edges' => [
            [
                'id'           => 'e1',
                'source'       => 'trigger',
                'target'       => 'note',
                'sourceHandle' => null,
            ],
        ],
    ];
    $created = WorkflowStore::create( "check-winback {$days}", $graph );
    if ( ! is_array( $created ) ) {
        WP_CLI::error( 'Could not create a temporary workflow.' );
    }
    $flows[] = (int) $created['id'];
    WorkflowStore::activate( (int) $created['id'] );
    return (int) $created['id'];
};

$email_n = 0;
$person  = function () use ( &$email_n, $domain ): string {
    ++$email_n;
    return "p{$email_n}@{$domain}";
};
// An order `$age` seconds old.
$order = function ( string $email, int $age, string $status = 'completed', int $customer_id = 0, int $created_at = 0 ) use ( &$orders ): int {
    $new = new WC_Order();
    $new->set_billing_email( $email );
    $new->set_customer_id( $customer_id );
    $new->set_status( $status );
    $new->set_date_created( $created_at ? $created_at : time() - $age );
    $new->save();
    $orders[] = $new->get_id();
    return $new->get_id();
};
// The script's own orders only: the sweep reads every order of the site.
$runs_of = function ( int $flow_id ) use ( $wpdb, &$orders ) {
    return array_values(
        array_intersect(
            $wpdb->get_col( $wpdb->prepare( 'SELECT subject FROM ' . RunStore::table() . ' WHERE workflow_id = %d ORDER BY id', $flow_id ) ),
            array_map( fn( int $order_id ) => "order:{$order_id}", $orders )
        )
    );
};
// The daily job, then the batches it queued, as Action Scheduler would.
$sweep = function (): void {
    InactiveSweep::run();
    $queued = as_get_scheduled_actions(
        [
            'hook'     => InactiveSweep::BATCH_HOOK,
            'status'   => 'pending',
            'per_page' => -1,
        ]
    );
    foreach ( $queued as $action ) {
        as_unschedule_action( InactiveSweep::BATCH_HOOK, $action->get_args(), ActionSchedulerQueue::GROUP );
        InactiveSweep::batch( ...$action->get_args() );
    }
};
$has   = fn( int $flow_id, int $order_id ) => in_array( "order:{$order_id}", $runs_of( $flow_id ), true );

const CHECK_DAY = DAY_IN_SECONDS;
// Far back, where a dev site has no orders of its own to start runs on.
const CHECK_DAYS = 613;
$margin          = MINUTE_IN_SECONDS;

// Nothing active: only the workflows read, no order query.
$order_queries = 0;
$count_queries = function ( $query_args ) use ( &$order_queries ) {
    ++$order_queries;
    return $query_args;
};
add_filter( 'woocommerce_order_query_args', $count_queries );
$sweep();
$ok( 0 === $order_queries, 'nothing active: no order query' );

$main = $flow( CHECK_DAYS );
$sweep();
remove_filter( 'woocommerce_order_query_args', $count_queries );
$ok( $order_queries > 0, 'one active: the same counter sees the query' );

// Window edges.
$younger = $order( $person(), CHECK_DAYS * CHECK_DAY - HOUR_IN_SECONDS * 23 );
$edge_n  = $order( $person(), CHECK_DAYS * CHECK_DAY + $margin );
$edge_n7 = $order( $person(), ( CHECK_DAYS + 7 ) * CHECK_DAY - $margin );
$older   = $order( $person(), ( CHECK_DAYS + 8 ) * CHECK_DAY );

// Which statuses are candidates.
$in_window  = ( CHECK_DAYS + 1 ) * CHECK_DAY;
$on_hold    = $order( $person(), $in_window, 'on-hold' );
$refunded   = $order( $person(), $in_window, 'refunded' );
$processing = $order( $person(), $in_window, 'processing' );
$pending    = $order( $person(), $in_window, 'pending' );
$failed     = $order( $person(), $in_window, 'failed' );
$cancelled  = $order( $person(), $in_window, 'cancelled' );

// A newer real order drops the older one; a newer not-real order doesn't. By email, and by account.
$users = [];
$user  = function () use ( &$users, $person ): int {
    $users[] = wp_insert_user(
        [
            'user_login' => 'winback_' . wp_generate_password( 8, false ),
            'user_pass'  => wp_generate_password(),
            'user_email' => $person(),
        ]
    );
    return (int) end( $users );
};
register_shutdown_function(
    function () use ( &$users ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ( $users as $user_id ) {
            wp_delete_user( $user_id );
        }
    }
);
$newer = [];
foreach ( [ 'on-hold', 'refunded', 'processing' ] as $status ) {
    $email = $person();
    $old   = $order( $email, $in_window );
    $order( $email, 2 * CHECK_DAY, $status );
    $newer[ "email {$status}" ] = $old;

    $account = $user();
    $old     = $order( $person(), $in_window, 'completed', $account );
    $order( $person(), 2 * CHECK_DAY, $status, $account );
    $newer[ "account {$status}" ] = $old;
}
$case_email = $person();
$case_old   = $order( $case_email, $in_window );
$order( strtoupper( $case_email ), 2 * CHECK_DAY );

$not_newer = [];
foreach ( [ 'pending', 'failed', 'cancelled' ] as $status ) {
    $email = $person();
    $old   = $order( $email, $in_window );
    $order( $email, 2 * CHECK_DAY, $status );
    $not_newer[ "email {$status}" ] = $old;

    $account = $user();
    $old     = $order( $person(), $in_window, 'completed', $account );
    $order( $person(), 2 * CHECK_DAY, $status, $account );
    $not_newer[ "account {$status}" ] = $old;
}

// Same second: the higher id is the last order.
$same_email = $person();
$same_at    = time() - $in_window;
$same_low   = $order( $same_email, 0, 'completed', 0, $same_at );
$same_high  = $order( $same_email, 0, 'completed', 0, $same_at );

// Skipped: anonymized, no billing email.
$anonymized_id = $order( $person(), $in_window );
$anonymized    = wc_get_order( $anonymized_id );
$anonymized->update_meta_data( '_anonymized', 'yes' );
$anonymized->save();
$no_email = $order( '', $in_window );

$second = $flow( CHECK_DAYS );
$other  = $flow( CHECK_DAYS + 30 );

$sweep();

$ok( ! $has( $main, $younger ), 'N-1 days old: no' );
$ok( $has( $main, $edge_n ), 'N days old (a minute over): yes' );
$ok( $has( $main, $edge_n7 ), 'N+7 days old (a minute under): yes' );
$ok( ! $has( $main, $older ), 'N+8 days old: no' );
$ok( $has( $main, $on_hold ) && $has( $main, $refunded ) && $has( $main, $processing ), 'on-hold, refunded and processing in the window are candidates' );
$ok( ! $has( $main, $pending ) && ! $has( $main, $failed ) && ! $has( $main, $cancelled ), 'pending, failed and cancelled are not' );
foreach ( $newer as $what => $old_id ) {
    $ok( ! $has( $main, $old_id ), "newer real order excludes: {$what}" );
}
$ok( ! $has( $main, $case_old ), 'newer guest order with the same email in another case excludes' );
foreach ( $not_newer as $what => $old_id ) {
    $ok( $has( $main, $old_id ), "newer not-real order doesn't exclude: {$what}" );
}
$ok( $has( $main, $edge_n ) && 0 === (int) wc_get_order( $edge_n )->get_customer_id(), 'guest included' );
$ok( $has( $main, $same_high ) && ! $has( $main, $same_low ), 'same second: only the higher id' );
$ok( ! $has( $main, $anonymized_id ), 'anonymized order skipped' );
$ok( ! $has( $main, $no_email ), 'no billing email skipped' );
$ok( $has( $second, $edge_n ) && $runs_of( $second ) === $runs_of( $main ), 'two workflows on the same days: one run each, same subjects' );
$ok( in_array( "order:{$edge_n}", $runs_of( $main ), true ), 'subject is order:<id>' );
$ok( [] === $runs_of( $other ), 'different days: not started' );

$before = count( $runs_of( $main ) );
$sweep();
$ok( count( $runs_of( $main ) ) === $before, 'second sweep: no new run' );

$repeat_email = $person();
$repeat_old   = $order( $repeat_email, $in_window );
$sweep();
$ok( $has( $main, $repeat_old ), 'a person new to the window starts' );
$repeat_new = $order( $repeat_email, $in_window - HOUR_IN_SECONDS );
$sweep();
$ok( $has( $main, $repeat_new ), 'a new order later N days old: a new run' );

// Schedule and health. The site's own job comes back as it was.
as_unschedule_all_actions( InactiveSweep::HOOK );
delete_option( Cleanup::CHECKED_OPTION );
Cleanup::ensure();
Cleanup::ensure();
$jobs = as_get_scheduled_actions(
    [
        'hook'     => InactiveSweep::HOOK,
        'status'   => 'pending',
        'per_page' => -1,
    ]
);
$job  = reset( $jobs );
$ok( 1 === count( $jobs ) && 'yaymatik' === $job->get_group() && DAY_IN_SECONDS === (int) $job->get_schedule()->get_recurrence(), 'ensure: one daily job in the yaymatik group' );

$check = fn() => current( array_filter( HealthCheck::run(), fn( array $row ) => 'inactive_sweep_job' === $row['id'] ) );
$ok( 'ok' === $check()['status'] && 0 === HealthCheck::fix( 'inactive_sweep_job' ), 'health: scheduled is ok; fix with no problem does nothing' );
as_unschedule_all_actions( InactiveSweep::HOOK );
$ok( 'problem' === $check()['status'] && 'schedule' === $check()['fix'], 'health: job removed is a problem' );
$ok( 1 === HealthCheck::fix( 'inactive_sweep_job' ) && 'ok' === $check()['status'], 'health: fix schedules it again' );
as_unschedule_all_actions( InactiveSweep::HOOK );
as_schedule_recurring_action( time() - DAY_IN_SECONDS - HOUR_IN_SECONDS, DAY_IN_SECONDS, InactiveSweep::HOOK, [], ActionSchedulerQueue::GROUP, true );
$ok( 'problem' === $check()['status'], 'health: more than a day late is a problem' );
as_unschedule_all_actions( InactiveSweep::HOOK );
as_schedule_recurring_action( time() - HOUR_IN_SECONDS, DAY_IN_SECONDS, InactiveSweep::HOOK, [], ActionSchedulerQueue::GROUP, true );
$ok( 'ok' === $check()['status'], 'health: under a day late is fine' );
as_unschedule_all_actions( InactiveSweep::HOOK );
InactiveSweep::schedule();

// Batches: pages of 200, a next page only after a full one, no pause.
foreach ( [ $main, $second, $other ] as $off ) {
    WorkflowStore::deactivate( $off );
}
$batch_days = CHECK_DAYS + 40;
$batch_flow = $flow( $batch_days );
for ( $i = 0; $i < 500; $i++ ) {
    $order( $person(), ( $batch_days + 1 ) * CHECK_DAY );
}
$next_pages = function () {
    return array_values(
        array_map(
            fn( $action ) => [ $action->get_args(), $action->get_schedule()->get_date()->getTimestamp() ],
            as_get_scheduled_actions(
                [
                    'hook'     => InactiveSweep::BATCH_HOOK,
                    'status'   => 'pending',
                    'per_page' => -1,
                ]
            )
        )
    );
};
$soon       = fn( array $pending, int $page ) => 1 === count( $pending ) && [ $batch_days, $page ] === $pending[0][0] && abs( $pending[0][1] - ( time() + 2 * MINUTE_IN_SECONDS ) ) < 60;
InactiveSweep::run();
InactiveSweep::run();
$first_job = $next_pages();
$ok( 1 === count( $first_job ) && [ $batch_days, 1 ] === $first_job[0][0], 'the daily job queues one batch per days value, not a second while one is pending' );
as_unschedule_all_actions( InactiveSweep::BATCH_HOOK );
foreach ( [
    [ 1, 200, 2 ],
    [ 2, 400, 3 ],
] as list( $page, $total, $next ) ) {
    InactiveSweep::batch( $batch_days, $page );
    $pending = $next_pages();
    $ok( count( $runs_of( $batch_flow ) ) === $total && $soon( $pending, $next ), "page {$page}: {$total} runs so far, the next page queued 2 minutes ahead" );
    as_unschedule_all_actions( InactiveSweep::BATCH_HOOK );
}
InactiveSweep::batch( $batch_days, 3 );
$ok( 500 === count( $runs_of( $batch_flow ) ) && [] === $next_pages(), 'page 3: 500 runs, a part page queues nothing' );
$ok( WorkflowStore::is_active( $batch_flow ), '500 runs: the workflow stays active, no pause' );
WorkflowStore::deactivate( $batch_flow );
InactiveSweep::batch( $batch_days, 1 );
$ok( 500 === count( $runs_of( $batch_flow ) ) && [] === $next_pages(), 'workflow off: the batch does nothing, the chain ends' );

// The template graphs, end to end.
$template      = function ( array $coupon, string $offer ) use ( &$flows ): int {
    $at      = fn( int $row ) => [
        'x' => 0,
        'y' => 200 * $row,
    ];
    $graph   = [
        'nodes' => [
            [
                'id'       => 'trigger',
                'kind'     => 'trigger',
                'position' => $at( 0 ),
                'data'     => [
                    'block'  => 'customer_inactive',
                    'config' => [ 'days' => CHECK_DAYS + 80 ],
                ],
            ],
            [
                'id'       => 'condition',
                'kind'     => 'condition',
                'position' => $at( 1 ),
                'data'     => [
                    'block'  => 'condition',
                    'config' => [
                        'match' => 'all',
                        'rules' => [
                            [
                                'field'     => 'customer.unsubscribed',
                                'operator'  => 'is_false',
                                'value'     => '',
                                'valueType' => 'value',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id'       => 'coupon',
                'kind'     => 'action',
                'position' => $at( 2 ),
                'data'     => [
                    'block'  => 'generate_coupon',
                    'config' => StepRegistry::get( 'generate_coupon' )->sanitize( $coupon + [ 'expiry_days' => 14 ] ),
                ],
            ],
            [
                'id'       => 'email',
                'kind'     => 'action',
                'position' => $at( 3 ),
                'data'     => [
                    'block'  => 'send_email',
                    'config' => [
                        'to'      => '{{ customer.email }}',
                        'subject' => 'We miss you at {{ site.name }}',
                        'heading' => 'It’s been a while',
                        'message' => '<p>Hi {{ customer.first_name | default: "there" }},</p><p>' . $offer . '</p><p>{{ steps.coupon.box }}</p><p>{{ site.shop_button }}</p><p>See you soon,<br>{{ site.name }}</p>',
                    ],
                ],
            ],
        ],
        'edges' => [
            [
                'id'     => 'e-trigger-condition',
                'source' => 'trigger',
                'target' => 'condition',
            ],
            [
                'id'           => 'e-condition-coupon',
                'source'       => 'condition',
                'target'       => 'coupon',
                'sourceHandle' => 'then',
            ],
            [
                'id'     => 'e-coupon-email',
                'source' => 'coupon',
                'target' => 'email',
            ],
        ],
    ];
    $created = WorkflowStore::create( 'check-winback template', $graph );
    if ( ! is_array( $created ) ) {
        WP_CLI::error( 'Could not create a temporary workflow.' );
    }
    $flows[] = (int) $created['id'];
    WorkflowStore::activate( (int) $created['id'] );
    return (int) $created['id'];
};
$discount_flow = $template(
    [
        'discount_type' => 'percent',
        'amount'        => 15,
    ],
    'It’s been a while since your last order. Here’s {{ steps.coupon.amount }} off your next one:'
);
$shipping_flow = $template(
    [
        'discount_type' => 'free_shipping',
        'amount'        => null,
    ],
    'It’s been a while since your last order. Here’s free shipping on your next order:'
);

$mails = [];
add_filter(
    'wp_mail',
    function ( array $mail ) use ( &$mails ) {
        $mails[] = $mail;
        return $mail;
    }
);
$away_email = $person();
$away       = $order( $away_email, ( CHECK_DAYS + 81 ) * CHECK_DAY );
$gone_email = $person();
$gone       = $order( $gone_email, ( CHECK_DAYS + 81 ) * CHECK_DAY );
UnsubscribeStore::add( $gone_email );
$sweep();
$run_of = function ( int $flow_id, int $order_id ) use ( $wpdb ): int {
    return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RunStore::table() . ' WHERE workflow_id = %d AND subject = %s', $flow_id, "order:{$order_id}" ) );
};
$finish = function ( int $run_id ) {
    as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $run_id ], ActionSchedulerQueue::GROUP );
    Runtime::worker()->run( $run_id );
    return [ RunStore::find( $run_id )['status'], array_column( RunStepStore::for_run( $run_id ), 'step_id' ) ];
};
$ok( [ 'completed', [ 'condition' ] ] === $finish( $run_of( $discount_flow, $gone ) ) && [] === $mails, 'unsubscribed: ends at the Condition’s No path, no coupon, no email' );

$output_of = function ( int $run_id, string $step_id ) {
    foreach ( RunStepStore::for_run( $run_id ) as $row ) {
        if ( $step_id === $row['step_id'] ) {
            return $row['output'];
        }
    }
    return [];
};
foreach ( [
    [ $discount_flow, 'percent', '15', 'off your next one:' ],
    [ $shipping_flow, 'free_shipping', '0', 'free shipping on your next order:' ],
] as list( $flow_id, $type, $amount, $line ) ) {
    $mails  = [];
    $run_id = $run_of( $flow_id, $away );
    $ok( [ 'completed', [ 'condition', 'coupon', 'email' ] ] === $finish( $run_id ), "{$type}: runs the condition, the coupon and the email" );
    $code      = (string) ( $output_of( $run_id, 'coupon' )['code'] ?? '' );
    $coupon    = new WC_Coupon( $code );
    $coupons[] = $coupon->get_id();
    $expires   = $coupon->get_date_expires();
    $ok(
        $coupon->get_id() > 0 && ( 'free_shipping' === $type ? $coupon->get_free_shipping() : $type === $coupon->get_discount_type() )
            && (float) $amount === (float) $coupon->get_amount() && [ $away_email ] === $coupon->get_email_restrictions()
            && $expires && abs( $expires->getTimestamp() - ( time() + 14 * DAY_IN_SECONDS ) ) < 2 * DAY_IN_SECONDS,
        "{$type}: coupon of the right kind, limited to the customer, about 14 days"
    );
    $mail = $mails[0] ?? [];
    $ok( 1 === count( $mails ) && ( is_array( $mail['to'] ) ? $mail['to'][0] : $mail['to'] ) === $away_email && false !== strpos( $mail['subject'], 'We miss you at' ), "{$type}: one email to the customer, subject" );
    $body = (string) ( $mail['message'] ?? '' );
    $ok( false !== strpos( $body, 'Hi there,' ) && false !== strpos( $body, $line ) && false !== strpos( $body, $code ) && false !== strpos( $body, 'See you soon' ), "{$type}: greeting, offer, coupon code, sign-off" );
}
$ok( 2 === count( $coupons ), 'both versions active: one customer gets both, the known limit' );

// Preview: the newest paid order, or sample data without one.
$newest  = wc_get_order( $order( $person(), 0, 'processing' ) );
$graph   = WorkflowStore::find( $discount_flow )['graph'];
$config  = current( array_filter( $graph['nodes'], fn( array $node ) => 'email' === $node['id'] ) )['data']['config'];
$preview = EmailPreview::render( $graph, 'email', $config );
$ok( ! is_wp_error( $preview ) && false === $preview['sample'] && (string) $newest->get_order_number() === (string) $preview['order'], 'preview: the newest paid order, not sample data' );
$ok( ! is_wp_error( $preview ) && false !== strpos( $preview['html'], 'YMSAMPLE' ) && false !== strpos( $preview['html'], '15%' ), 'preview: the coupon box, 15%' );
$none    = fn() => [];
add_filter( 'woocommerce_order_query', $none );
$preview = EmailPreview::render( $graph, 'email', $config );
remove_filter( 'woocommerce_order_query', $none );
$ok( ! is_wp_error( $preview ) && true === $preview['sample'], 'preview, no orders: sample data' );

echo $fails ? "{$fails} failed\n" : "All passed\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
exit( $fails ? 1 : 0 );
