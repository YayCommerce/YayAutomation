<?php
/**
 * Trigger claims on the real table, with workflow ids no real workflow has, plus temporary workflows.
 * The inactive sweep on real orders at a random `.test` domain, through the real Dispatcher. Runs stay queued: their jobs are removed.
 * Refuses to run while the site has an active Customer inactive workflow: it would start on these orders.
 * Cleanup also deletes the site's own old runs and carts, and claims of its deleted workflows.
 * Prints PASS / FAIL lines; removes its rows. Run through check-winback.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

use YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Core\Runtime\Cleanup;
use YayMatik\Workflow\Core\Runtime\HealthCheck;
use YayMatik\Workflow\Core\Runtime\InactiveSweep;
use YayMatik\Workflow\Core\Runtime\Runtime;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\Schema;
use YayMatik\Workflow\Core\Storage\TriggerClaimStore;
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
$domain  = 'x' . wp_rand( 1000, 9999 ) . '.test';

$fails = 0;
$ok    = function ( bool $pass, string $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $fails += $pass ? 0 : 1;
};

$cleanup = function () use ( $wpdb, $claims, $ghost, &$flows, &$orders ) {
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
$sweep   = [ InactiveSweep::class, 'run' ];
$has     = fn( int $flow_id, int $order_id ) => in_array( "order:{$order_id}", $runs_of( $flow_id ), true );

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
Runtime::dispatcher()->flush();

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
Runtime::dispatcher()->flush();
$ok( count( $runs_of( $main ) ) === $before, 'second sweep: no new run' );

$repeat_email = $person();
$repeat_old   = $order( $repeat_email, $in_window );
$sweep();
Runtime::dispatcher()->flush();
$ok( $has( $main, $repeat_old ), 'a person new to the window starts' );
$repeat_new = $order( $repeat_email, $in_window - HOUR_IN_SECONDS );
$sweep();
Runtime::dispatcher()->flush();
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

echo $fails ? "{$fails} failed\n" : "All passed\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
exit( $fails ? 1 : 0 );
