<?php
/**
 * Trigger claims on the real table, with workflow ids no real workflow has, plus one temporary workflow.
 * Cleanup also deletes the site's own old runs and carts, and claims of its deleted workflows.
 * Prints PASS / FAIL lines; removes its rows. Run through check-winback.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

use YayMatik\Workflow\Core\Runtime\Cleanup;
use YayMatik\Workflow\Core\Storage\Schema;
use YayMatik\Workflow\Core\Storage\TriggerClaimStore;
use YayMatik\Workflow\Core\Storage\WorkflowStore;

if ( ! function_exists( 'WC' ) ) {
    WP_CLI::error( 'WooCommerce is not active.' );
}

global $wpdb;
$claims  = Schema::table( Schema::TRIGGER_CLAIMS );
$ghost   = 999999;
$subject = 'order:' . wp_rand( 100000, 999999 );
$flows   = [];

$fails = 0;
$ok    = function ( bool $pass, string $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $fails += $pass ? 0 : 1;
};

$cleanup = function () use ( $wpdb, $claims, $ghost, &$flows ) {
    foreach ( $flows as $flow_id ) {
        WorkflowStore::delete( $flow_id );
        $wpdb->delete( $claims, [ 'workflow_id' => $flow_id ] );
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

echo $fails ? "{$fails} failed\n" : "All passed\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
exit( $fails ? 1 : 0 );
