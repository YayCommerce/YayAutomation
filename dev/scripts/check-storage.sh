#!/bin/bash
# Check the storage (wp-cli): runs, versions, the workflow lifecycle. Removes its test rows.
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1

# Test rows use a workflow id no real workflow has.
"${WP[@]}" eval '
use YayMatik\Workflow\Core\Storage\{RunStore as R, VersionStore as V, WorkflowStore as WS, Schema};
use YayMatik\Workflow\Core\Data\{Scope, Ref};
global $wpdb;
$W     = 999999;
$fails = 0;
$ok    = function ( $pass, $what ) use ( &$fails ) {
    echo ( $pass ? "PASS " : "FAIL " ), $what, "\n";
    $fails += $pass ? 0 : 1;
};
$created = [];
$cleanup = function () use ( $wpdb, $W, &$created ) {
    $wpdb->delete( R::table(), [ "workflow_id" => $W ] );
    foreach ( $created as $id ) {
        $wpdb->delete( Schema::table( Schema::WORKFLOWS ), [ "id" => $id ] );
        $wpdb->delete( V::table(), [ "workflow_id" => $id ] );
    }
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

// Workflow lifecycle through REST, as the editor calls it.
wp_set_current_user( 1 );
$call = function ( string $method, string $route, ?array $body = null ) {
    $request = new WP_REST_Request( $method, "/yaymatik/v1/" . $route );
    if ( null !== $body ) {
        $request->set_header( "content-type", "application/json" );
        $request->set_body( wp_json_encode( $body ) );
    }
    return rest_do_request( $request );
};
$graph = fn( string $note, int $y = 170 ) => [
    "nodes" => [
        [ "id" => "trigger", "kind" => "trigger", "position" => [ "x" => 0, "y" => 0 ], "data" => [ "block" => "order_created", "config" => (object) [] ] ],
        [ "id" => "note", "kind" => "action", "position" => [ "x" => 0, "y" => $y ], "data" => [ "block" => "add_order_note", "config" => [ "note" => $note ] ] ],
    ],
    "edges" => [ [ "id" => "e0", "source" => "trigger", "target" => "note" ] ],
];
$wf_table = Schema::table( Schema::WORKFLOWS );
$row      = fn( int $id ) => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wf_table} WHERE id = %d", $id ), ARRAY_A );
$count    = fn( int $id ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . V::table() . " WHERE workflow_id = %d", $id ) );
$note_of  = fn( $g ) => $g["nodes"][1]["data"]["config"]["note"] ?? null;
$y_of     = fn( $g ) => (int) ( $g["nodes"][1]["position"]["y"] ?? -1 );
// Puts the times in the past, so a write in the same second still shows.
$age = function ( int $id ) use ( $wpdb, $wf_table ) {
    $old = "2020-01-01 00:00:00";
    $wpdb->query( $wpdb->prepare( "UPDATE {$wf_table} SET updated_at = %s WHERE id = %d", $old, $id ) );
    $wpdb->query( $wpdb->prepare( "UPDATE " . V::table() . " SET updated_at = %s WHERE workflow_id = %d", $old, $id ) );
    return $old;
};
// Breaks every query containing `$needle` until the returned remover runs; the expected database errors are not printed.
$break = function ( string $needle, string $with ) use ( $wpdb ) {
    $filter = fn( $sql ) => str_replace( $needle, $with, $sql );
    add_filter( "query", $filter );
    $shown = $wpdb->hide_errors();
    return function () use ( $wpdb, $filter, $shown ) {
        remove_filter( "query", $filter );
        $shown && $wpdb->show_errors();
    };
};

$res = $call( "POST", "workflows", [ "name" => "Check storage (temporary)", "graph" => $graph( "v1" ) ] );
$id  = (int) ( $res->get_data()["id"] ?? 0 );
if ( $id ) {
    $created[] = $id;
}
$r = $row( $id );
$ok( 201 === $res->get_status() && $r && "inactive" === $r["status"] && "order_created" === $r["trigger_id"] && (int) $r["live_version_id"] > 0 && 0 === (int) $r["draft_version_id"], "create: inactive, trigger_id, live version set, no draft" );
$live = (int) $r["live_version_id"];

$res = $call( "PUT", "workflows/{$id}", [ "graph" => $graph( "v2" ) ] );
$r   = $row( $id );
$ok( 200 === $res->get_status() && (int) $r["live_version_id"] === $live && 0 === (int) $r["draft_version_id"] && 1 === $count( $id ) && "v2" === $note_of( V::find( $live )["graph"] ), "edit before activation: live row updated in place" );

$res = $call( "POST", "workflows/{$id}/activate" );
$r   = $row( $id );
$ok( 200 === $res->get_status() && "active" === $r["status"] && null !== $r["activated_at"] && (int) $r["live_version_id"] > $live && 2 === $count( $id ), "activate: active, new live version" );
$live = (int) $r["live_version_id"];
$ok( ! array_key_exists( "position", V::find( $live )["graph"]["nodes"][0] ) && isset( V::find( $live )["layout"]["note"] ), "version row: logic without positions, layout apart" );

$old = $age( $id );
$res = $call( "PUT", "workflows/{$id}", [ "graph" => $graph( "v2", 400 ) ] );
$r   = $row( $id );
$v   = V::find( $live );
$ok( 200 === $res->get_status() && 0 === (int) $r["draft_version_id"] && 2 === $count( $id ) && (int) $r["live_version_id"] === $live, "layout-only save on live: no draft, no version" );
$ok( $old === $r["updated_at"] && $old === $v["updated_at"] && 400 === $y_of( WS::find( $id )["graph"] ), "layout-only save: updated_at kept, position read back" );

$res   = $call( "PUT", "workflows/{$id}", [ "graph" => $graph( "v3", 400 ) ] );
$r     = $row( $id );
$draft = (int) $r["draft_version_id"];
$wf    = WS::find( $id );
$ok( 200 === $res->get_status() && $draft > $live && (int) $r["live_version_id"] === $live && "v2" === $note_of( $wf["graph"] ) && "v3" === $note_of( $wf["draft"] ), "draft: own row, live unchanged" );

$old = $age( $id );
$call( "PUT", "workflows/{$id}", [ "graph" => $graph( "v3", 500 ) ] );
$v = V::find( $draft );
$ok( $old === $v["updated_at"] && $old === $row( $id )["updated_at"] && 500 === $y_of( WS::find( $id )["draft"] ) && 400 === $y_of( WS::find( $id )["graph"] ), "layout-only save with a draft: draft row only, times kept" );

$res = $call( "POST", "workflows/{$id}/apply" );
$r   = $row( $id );
$ok( 200 === $res->get_status() && (int) $r["live_version_id"] === $draft && 0 === (int) $r["draft_version_id"] && "v3" === $note_of( WS::find( $id )["graph"] ), "apply: draft row becomes live" );
$live = $draft;

// The lock query fails: an error at once, not a 10 s wait, and nothing written.
$versions = $count( $id );
$restore  = $break( "GET_LOCK(", "GET_LOCK_BROKEN(" );
$start    = microtime( true );
$res      = $call( "POST", "workflows/{$id}/deactivate" );
$took     = microtime( true ) - $start;
$restore();
$r = $row( $id );
$ok( 500 === $res->get_status() && "yaymatik_save_failed" === ( $res->get_data()["code"] ?? "" ) && $took < 2, "lock query broken: deactivate → 500 yaymatik_save_failed at once" );
$ok( "active" === $r["status"] && (int) $r["live_version_id"] === $live && $count( $id ) === $versions, "lock query broken: workflow unchanged" );

$res = $call( "POST", "workflows/{$id}/deactivate" );
$r   = $row( $id );
$ok( 200 === $res->get_status() && "inactive" === $r["status"] && (int) $r["live_version_id"] > $live && 500 === $y_of( WS::find( $id )["graph"] ), "deactivate: new live version, layout copied" );
$live = (int) $r["live_version_id"];

// A failed version insert rolls the whole write back.
$versions = $count( $id );
$restore  = $break( "INSERT INTO `" . V::table() . "`", "INSERT INTO `" . V::table() . "_broken`" );
$res      = $call( "POST", "workflows/{$id}/activate" );
$r        = $row( $id );
$ok( 500 === $res->get_status() && "inactive" === $r["status"] && (int) $r["live_version_id"] === $live && null !== $r["activated_at"] && $count( $id ) === $versions, "insert fails on activate: 500, nothing changed" );
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wf_table}" );
$res    = $call( "POST", "workflows", [ "name" => "Check storage (temporary)", "graph" => $graph( "v1" ) ] );
$restore();
$ok( 500 === $res->get_status() && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wf_table}" ) === $before, "insert fails on create: 500, no workflow row" );

$ok( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wf_table} WHERE live_version_id = 0" ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . V::table() . " WHERE id = 0" ), "no version id 0 anywhere" );

$res = $call( "DELETE", "workflows/{$id}" );
$ok( 200 === $res->get_status() && null === $row( $id ) && 0 === $count( $id ), "delete: workflow and its versions gone" );

echo $fails ? "$fails failed\n" : "All passed\n";
exit( $fails ? 1 : 0 );
'
