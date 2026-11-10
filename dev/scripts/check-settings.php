<?php
/**
 * Settings and Health check REST, as the Settings page calls them; run through check-settings.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Core\Runtime\Cleanup;
use YayMatik\Workflow\Core\Runtime\InactiveSweep;
use YayMatik\Workflow\Core\Storage\RunStore;

require_once __DIR__ . '/check-run.php';

global $wpdb;
$fails = 0;
$ok    = function ( $pass, $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $fails += $pass ? 0 : 1;
};

$admin = get_users(
    [
        'role'   => 'administrator',
        'number' => 1,
        'fields' => 'ID',
    ]
);
wp_set_current_user( (int) ( $admin[0] ?? 0 ) );
$call = function ( string $method, string $route, $body = null ) {
    $request = new WP_REST_Request( $method, '/yaymatik/v1/' . $route );
    if ( null !== $body ) {
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( is_string( $body ) ? $body : wp_json_encode( $body ) );
    }
    return rest_do_request( $request );
};

$fields = fn( $res ) => array_column( $res->get_data()['data']['errors'] ?? [], 'field' );

// Test runs use a workflow id no real workflow has. The daily jobs keep their times: the fixes below reschedule them.
$w       = 999998;
$stored  = get_option( 'yaymatik_settings', null );
$checked = get_option( Cleanup::CHECKED_OPTION, null );
$daily   = [];
foreach ( [ Cleanup::HOOK, InactiveSweep::HOOK ] as $hook ) {
    $daily[ $hook ] = as_next_scheduled_action( $hook, [], ActionSchedulerQueue::GROUP );
}
$cleaned = false;
$cleanup = function () use ( $w, $stored, $checked, $daily, &$cleaned ) {
    if ( $cleaned ) {
        return;
    }
    $cleaned = true;
    null === $stored ? delete_option( 'yaymatik_settings' ) : update_option( 'yaymatik_settings', $stored, true );
    yaymatik_check_remove_runs( 0, [ $w ] );
    as_unschedule_all_actions( 'yaymatik_check_settings_late', [], ActionSchedulerQueue::GROUP );
    foreach ( $daily as $hook => $next ) {
        if ( is_int( $next ) ) {
            as_unschedule_all_actions( $hook, [], ActionSchedulerQueue::GROUP );
            as_schedule_recurring_action( $next, DAY_IN_SECONDS, $hook, [], ActionSchedulerQueue::GROUP, true );
        }
    }
    null === $checked ? delete_option( Cleanup::CHECKED_OPTION ) : update_option( Cleanup::CHECKED_OPTION, $checked, true );
};
// Runs even when a check throws or PHP dies, so the site keeps its settings and jobs.
register_shutdown_function( $cleanup );

echo "--- settings\n";
$res = $call( 'GET', 'settings' );
$ok( 200 === $res->get_status() && [ 'cartTracking', 'abandonMinutes', 'captureGuestEmail', 'cartRetentionDays', 'runRetentionDays', 'unsubscribeText', 'unsubscribeDefault' ] === array_keys( $res->get_data() ), 'GET: every setting, camelCase, plus unsubscribeDefault' );
$before = $res->get_data();

$res = $call( 'PUT', 'settings', [ 'abandonMinutes' => 30 ] );
$ok( 200 === $res->get_status() && 30 === $res->get_data()['abandonMinutes'] && $before['runRetentionDays'] === $res->get_data()['runRetentionDays'], 'PUT one key: saved, the others kept' );

$ranges = [
    'abandonMinutes'    => [ 15, 1440 ],
    'cartRetentionDays' => [ 7, 365 ],
    'runRetentionDays'  => [ 7, 730 ],
];
foreach ( $ranges as $key => list( $min, $max ) ) {
    foreach ( [ $min, $max ] as $value ) {
        $res = $call( 'PUT', 'settings', [ $key => $value ] );
        $ok( 200 === $res->get_status() && $value === $res->get_data()[ $key ], "{$key} {$value} (edge) saved" );
    }
    foreach ( [ $min - 1, $max + 1, (string) $min, $min + 0.5, null ] as $value ) {
        $res = $call( 'PUT', 'settings', [ $key => $value ] );
        $ok(
            400 === $res->get_status() && [ $key ] === $fields( $res ) && "Enter a whole number from {$min} to {$max}." === ( $res->get_data()['data']['errors'][0]['message'] ?? '' ),
            "{$key} " . wp_json_encode( $value ) . ' → 400 on that field'
        );
    }
}
foreach ( [ 'cartTracking', 'captureGuestEmail' ] as $key ) {
    $res = $call( 'PUT', 'settings', [ $key => 'yes' ] );
    $ok( 400 === $res->get_status() && [ $key ] === $fields( $res ) && 'Choose on or off.' === $res->get_data()['data']['errors'][0]['message'], "{$key} \"yes\" → 400" );
    $res = $call( 'PUT', 'settings', [ $key => true ] );
    $ok( 200 === $res->get_status() && true === $res->get_data()[ $key ], "{$key} true saved" );
}
$res = $call( 'PUT', 'settings', [ 'unsubscribeText' => 5 ] );
$ok( 400 === $res->get_status() && [ 'unsubscribeText' ] === $fields( $res ) && 'Enter text.' === $res->get_data()['data']['errors'][0]['message'], 'unsubscribeText 5 → 400' );
$res = $call( 'PUT', 'settings', [ 'unsubscribeText' => '<b>Bye</b> ' . str_repeat( 'x', 1200 ) ] );
$ok( 200 === $res->get_status() && 0 === strpos( $res->get_data()['unsubscribeText'], 'Bye x' ) && 1000 === mb_strlen( $res->get_data()['unsubscribeText'] ), 'unsubscribeText: tags stripped, cut to 1000 characters' );

$call( 'PUT', 'settings', [ 'abandonMinutes' => 45 ] );
$res = $call(
    'PUT',
    'settings',
    [
        'abandonMinutes'   => 60,
        'runRetentionDays' => 5,
        'cartTracking'     => 'on',
    ]
);
$ok( 400 === $res->get_status() && 'yaymatik_invalid_settings' === $res->get_data()['code'] && [ 'cartTracking', 'runRetentionDays' ] === $fields( $res ), 'two bad fields → one 400 entry each, in settings order' );
$ok( 45 === $call( 'GET', 'settings' )->get_data()['abandonMinutes'], 'all or nothing: the valid abandonMinutes in that request was not saved' );
$res = $call( 'PUT', 'settings', [ 'unknownKey' => 1 ] );
$ok( 200 === $res->get_status() && ! array_key_exists( 'unknownKey', $res->get_data() ), 'an unknown key is ignored' );
$res = $call( 'PUT', 'settings', '"text"' );
$ok( 400 === $res->get_status() && 'yaymatik_invalid_body' === $res->get_data()['code'], 'a body that is not an object → 400 yaymatik_invalid_body' );
wp_set_current_user( 0 );
$ok( 401 === $call( 'GET', 'settings' )->get_status() && 401 === $call( 'PUT', 'settings', [ 'abandonMinutes' => 30 ] )->get_status(), 'logged out: GET and PUT → 401' );
wp_set_current_user( (int) ( $admin[0] ?? 0 ) );

echo "--- health check\n";
$check = function ( string $id ) use ( $call ) {
    foreach ( $call( 'GET', 'health/checks' )->get_data()['checks'] as $found ) {
        if ( $id === $found['id'] ) {
            return $found;
        }
    }
    return null;
};

$res = $call( 'GET', 'health/checks' );
$ok( 200 === $res->get_status() && [ 'runs_queued', 'runs_running', 'jobs_late', 'cleanup_job', 'inactive_sweep_job' ] === array_column( $res->get_data()['checks'], 'id' ) && false !== strtotime( $res->get_data()['checkedAt'] ), 'GET /health/checks: the 5 checks and checkedAt' );
foreach ( [ 'jobs_late', 'nope', '' ] as $check_id ) {
    $ok( 400 === $call( 'POST', 'health/fix', [ 'check' => $check_id ] )->get_status(), "fix \"{$check_id}\" → 400" );
}

foreach ( [
    'cleanup_job'        => Cleanup::HOOK,
    'inactive_sweep_job' => InactiveSweep::HOOK,
] as $check_id => $hook ) {
    as_unschedule_all_actions( $hook, [], ActionSchedulerQueue::GROUP );
    $found = $check( $check_id );
    $ok( 'problem' === $found['status'] && 'schedule' === $found['fix'], "{$check_id}: job missing → problem, fix schedule" );
    $res = $call( 'POST', 'health/fix', [ 'check' => $check_id ] );
    $ok( 200 === $res->get_status() && 1 === $res->get_data()['fixed'] && as_has_scheduled_action( $hook, [], ActionSchedulerQueue::GROUP ), "{$check_id}: fix schedules it again" );
    $ok( 'ok' === $check( $check_id )['status'] && 0 === $call( 'POST', 'health/fix', [ 'check' => $check_id ] )->get_data()['fixed'], "{$check_id}: ok after the fix; fixing again does nothing" );
}

$past  = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
$stuck = function ( string $status ) use ( $wpdb, $w, $past ) {
    $run_id = RunStore::create(
        [
            'workflow_id' => $w,
            'version_id'  => 1,
            'trigger_id'  => 'order_created',
            'subject'     => 'order:0',
        ]
    );
    $wpdb->update(
        RunStore::table(),
        [
            'status'     => $status,
            'started_at' => $past,
        ],
        [ 'id' => $run_id ]
    );
    return $run_id;
};
foreach ( [
    'runs_queued'  => [ 'queued', 'enqueue', ActionSchedulerQueue::HOOK ],
    'runs_running' => [ 'running', 'recover', ActionSchedulerQueue::RECOVER_HOOK ],
] as $check_id => list( $run_status, $fix, $hook ) ) {
    // The fix takes every stuck run: with the site's own, it would resume them too.
    $own = $check( $check_id )['count'];
    if ( $own ) {
        echo "SKIP {$check_id}: the site has {$own} stuck run(s) of its own\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        continue;
    }
    $run   = $stuck( $run_status );
    $found = $check( $check_id );
    $ok( 'problem' === $found['status'] && $fix === $found['fix'] && in_array( $run, $found['runIds'], true ), "{$check_id}: a {$run_status} run with no job → problem, fix {$fix}" );
    $res = $call( 'POST', 'health/fix', [ 'check' => $check_id ] );
    $has = as_has_scheduled_action( $hook, [ $run ], ActionSchedulerQueue::GROUP );
    // The job is removed at once, so the site's runner never takes this test run.
    as_unschedule_all_actions( $hook, [ $run ], ActionSchedulerQueue::GROUP );
    $ok( 200 === $res->get_status() && $res->get_data()['fixed'] >= 1 && $has, "{$check_id}: fix gives the run a {$hook} job" );
    $wpdb->delete( RunStore::table(), [ 'id' => $run ] );
}

$late_before = $check( 'jobs_late' )['count'];
as_schedule_single_action( time() - HOUR_IN_SECONDS, 'yaymatik_check_settings_late', [], ActionSchedulerQueue::GROUP );
$found = $check( 'jobs_late' );
$ok( 'info' === $found['status'] && $late_before + 1 === $found['count'] && null === $found['fix'] && ! empty( $found['guide'] ), 'jobs_late: a job an hour late is counted → info, no fix, a cron guide' );

echo $fails ? "{$fails} failed\n" : "All passed\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
if ( $fails ) {
    WP_CLI::halt( 1 );
}
