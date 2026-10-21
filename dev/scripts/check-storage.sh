#!/bin/bash
# Check the run storage on the local site (wp-cli): runs, versions. Removes its test rows.
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
command -v wp >/dev/null || { echo "Missing: wp-cli"; exit 1; }

# Test rows use a workflow id no real workflow has.
wp ${WP_PATH:+--path="$WP_PATH"} eval '
use YayMatik\Workflow\Core\Storage\{RunStore as R, VersionStore as V};
use YayMatik\Workflow\Core\Data\{Scope, Ref};
global $wpdb;
$W     = 999999;
$fails = 0;
$ok    = function ( $pass, $what ) use ( &$fails ) {
    echo ( $pass ? "PASS " : "FAIL " ), $what, "\n";
    $fails += $pass ? 0 : 1;
};
$cleanup = function () use ( $wpdb, $W ) {
    $wpdb->delete( R::table(), [ "workflow_id" => $W ] );
};
$cleanup();
// Runs even when a check throws or PHP dies, so no test rows are left behind.
register_shutdown_function( $cleanup );
// A site timezone far from UTC, so the GMT checks mean something. A filter, not update_option():
// it lives only in this process, so the stored timezone never changes and needs no restore.
add_filter( "pre_option_timezone_string", fn() => "Asia/Ho_Chi_Minh" );
add_filter( "pre_option_gmt_offset", fn() => 7 );
$ok( current_time( "mysql" ) !== current_time( "mysql", true ), "site time differs from GMT for this check" );

$scope = new Scope( [ "trigger" => [ "order" => new Ref( "order", 12 ), "status_from" => "pending" ] ] );
$id    = R::create( [ "workflow_id" => $W, "version_id" => 5, "trigger_id" => "order_created", "subject" => "order:12", "scope" => $scope->to_array() ] );
$r     = R::find( $id );
$ok( $r["status"] === "queued" && $r["root_run_id"] === $id && $r["depth"] === 0 && null === $r["finished_at"], "create: queued, own root" );
$ok( abs( strtotime( $r["started_at"] . " UTC" ) - time() ) < 60, "started_at is GMT" );
$ok( Scope::from_array( $r["scope"] )->to_array() === $scope->to_array(), "scope JSON round-trip through the row" );
$child = R::create( [ "workflow_id" => $W, "version_id" => 5, "parent_run_id" => $id, "root_run_id" => $id, "depth" => 1 ] );
$ok( R::find( $child )["root_run_id"] === $id && R::find( $child )["depth"] === 1, "child keeps root and depth" );
$ok( R::claim( $id ) === true, "claim queued → running" );
$ok( R::claim( $id ) === false, "second claim refused" );
$ok( R::save( $id, [ "position" => [ "node" => "action-1", "stack" => [] ], "status" => "waiting", "wait_until" => "2030-01-01 00:00:00" ] ), "save position / status / wait_until" );
$r = R::find( $id );
$ok( $r["position"] === [ "node" => "action-1", "stack" => [] ] && $r["status"] === "waiting" && $r["wait_until"] === "2030-01-01 00:00:00", "saved values read back" );
$ok( R::claim( $id ) === false && R::find( $id )["status"] === "waiting", "waiting run with a future wait_until refused" );
$past = gmdate( "Y-m-d H:i:s", time() - 60 );
R::save( $id, [ "wait_until" => $past ] );
// Claim keeps wait_until: the Executor reads it, then clears it.
$r = R::claim( $id ) ? R::find( $id ) : null;
$ok( $r && $r["status"] === "running" && $r["wait_until"] === $past, "waiting run whose wait_until passed claimed, wait_until kept" );
$ok( R::save( $id, [ "status" => "bogus" ] ) === false && R::find( $id )["status"] === "running", "unknown status rejected" );
R::save( $id, [ "status" => "completed", "error" => null ] );
$r = R::find( $id );
$ok( $r["status"] === "completed" && null !== $r["finished_at"], "finishing status sets finished_at" );
$ok( abs( strtotime( $r["finished_at"] . " UTC" ) - time() ) < 60, "finished_at is GMT" );
$ok( R::claim( $id ) === false, "finished run cannot be claimed" );
$list = R::list( [ "workflow_id" => $W ] );
$ok( count( $list ) === 2 && $list[0]["id"] === $child && ! array_key_exists( "scope", $list[0] ), "list newest first, without scope" );
$ok( count( R::list( [ "workflow_id" => $W, "status" => "completed" ] ) ) === 1, "list by status" );
$ok( count( R::list( [ "workflow_id" => $W, "status" => [ "queued", "nope" ] ] ) ) === 1, "unknown status filter ignored" );
$vid = (int) $wpdb->get_var( "SELECT id FROM " . V::table() . " ORDER BY id DESC LIMIT 1" );
$v   = V::find( $vid );
$ok( ! $vid || ( $v && $v["id"] === $vid && is_array( $v["graph"] ) ), "VersionStore::find decodes the graph" );
$ok( null === V::find( 0 ), "VersionStore::find missing → null" );

echo $fails ? "$fails failed\n" : "All passed\n";
exit( $fails ? 1 : 0 );
'
