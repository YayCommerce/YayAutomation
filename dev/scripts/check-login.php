<?php
/**
 * Login triggers on the real tables (wp-cli). Claims: a claim with a cooldown on a workflow id no real workflow has.
 * Prints PASS / FAIL lines; removes its rows. Run through check-login.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

use YayMatik\Workflow\Core\Storage\Schema;
use YayMatik\Workflow\Core\Storage\TriggerClaimStore;

global $wpdb;
$claims  = Schema::table( Schema::TRIGGER_CLAIMS );
$ghost   = 999998;
$subject = 'user:' . wp_rand( 100000, 999999 );

$fails = 0;
$ok    = function ( bool $pass, string $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $fails += $pass ? 0 : 1;
};

$cleanup = function () use ( $wpdb, $claims, $ghost ) {
    $wpdb->delete( $claims, [ 'workflow_id' => $ghost ] );
};
register_shutdown_function( $cleanup );
$cleanup();

$age = function ( int $seconds ) use ( $wpdb, $claims, $ghost, $subject ) {
    $wpdb->update( $claims, [ 'claimed_at' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ], [ 'workflow_id' => $ghost, 'subject' => $subject ] );
};

// D1: once forever without a cooldown; again only after it with one.
$ok( TriggerClaimStore::claim( $ghost, $subject, DAY_IN_SECONDS ), 'claim: the first claim wins' );
$ok( ! TriggerClaimStore::claim( $ghost, $subject, DAY_IN_SECONDS ), 'claim: refused inside the cooldown' );
$age( DAY_IN_SECONDS + 5 );
$ok( ! TriggerClaimStore::claim( $ghost, $subject ), 'claim: without a cooldown, never again' );
$ok( TriggerClaimStore::claim( $ghost, $subject, DAY_IN_SECONDS ), 'claim: taken again after the cooldown' );
$ok( ! TriggerClaimStore::claim( $ghost, $subject, DAY_IN_SECONDS ), 'claim: the new claim starts a new cooldown' );
TriggerClaimStore::release( $ghost, $subject );
$ok( TriggerClaimStore::claim( $ghost, $subject, DAY_IN_SECONDS ), 'claim: released, then claimed again at once' );

// Two at once: this process takes the old claim inside an open transaction, which holds the row lock; a second
// wp process claims the same key meanwhile and must wait for the commit, then find nothing to take.
$age( DAY_IN_SECONDS + 5 );
$wpdb->query( 'START TRANSACTION' );
$first  = TriggerClaimStore::claim( $ghost, $subject, DAY_IN_SECONDS );
$code   = sprintf( '$t = microtime( true ); $won = YayMatik\Workflow\Core\Storage\TriggerClaimStore::claim( %d, %s, DAY_IN_SECONDS ); echo (int) $won, " ", round( microtime( true ) - $t, 1 );', $ghost, var_export( $subject, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
$second = proc_open( [ 'wp', 'eval', $code ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
// Long enough for the second process to boot WordPress and reach the lock.
sleep( 6 );
$wpdb->query( 'COMMIT' );
list( $won, $waited ) = array_pad( explode( ' ', trim( (string) stream_get_contents( $pipes[1] ) ) ), 2, '' );
$errors               = trim( (string) stream_get_contents( $pipes[2] ) );
proc_close( $second );
$ok( $first && '0' === $won, "claim: two at once, one wins (first: {$first}, second: '{$won}'" . ( $errors ? ", stderr: {$errors}" : '' ) . ')' );
$ok( (float) $waited >= 1, "claim: the second waited {$waited} s for the first one's row lock" );

echo $fails ? "{$fails} check(s) failed.\n" : "All checks passed.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
if ( $fails ) {
    exit( 1 );
}
