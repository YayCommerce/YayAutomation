#!/bin/bash
# Delete all workflows, versions, runs, carts, trigger claims and settings; keeps unsubscribes. --yes skips the prompt. Target: local site, --wp-env, --wp-env-min or --staging.
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }

yes=false
target=
for arg in "$@"; do
  if [[ $arg == --yes ]]; then yes=true; else target=$arg; fi
done
. dev/scripts/lib/wp-target.sh "$target" || exit 1

if ! $yes; then
  read -r -p "Delete every YayMatik workflow, version, run, cart and setting on ${target:-the local site}? Type yes: " answer
  [[ $answer == yes ]] || { echo "Cancelled."; exit 1; }
fi

# The plugin stays unloaded, or its installer would recreate the tables first. Also clears what dev builds
# before the workflows table left behind: the yaymatik_wf posts and the versions table.
"${WP[@]}" --skip-plugins="$PLUGIN_FOLDER" eval '
global $wpdb;
$meta = $wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = '\''yaymatik_wf'\''" );
$posts = $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = '\''yaymatik_wf'\''" );
echo "Workflow posts: {$posts} (meta rows: {$meta})\n";
foreach ( [ "workflows", "versions", "workflow_versions", "runs", "run_steps", "carts", "trigger_claims" ] as $name ) {
    $table = $wpdb->prefix . "yaymatik_" . $name;
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $table ) ) ) === $table ) {
        $wpdb->query( "DROP TABLE {$table}" );
        echo "Dropped {$table}\n";
    }
}
foreach ( [ "yaymatik_db_version", "yaymatik_settings", "yaymatik_cart_secret", "yaymatik_cleanup_checked_at", "yaymatik_active_triggers" ] as $option ) {
    echo delete_option( $option ) ? "Deleted option {$option}\n" : "";
}
if ( function_exists( "as_unschedule_all_actions" ) ) {
    foreach ( [ "yaymatik_run", "yaymatik_recover_run", "yaymatik_cleanup", "yaymatik_cart_sweep", "yaymatik_inactive_sweep", "yaymatik_inactive_sweep_batch" ] as $hook ) {
        $pending = count( as_get_scheduled_actions( [ "hook" => $hook, "status" => "pending", "per_page" => -1 ], "ids" ) );
        as_unschedule_all_actions( $hook );
        echo $pending ? "Unscheduled {$pending} {$hook} actions\n" : "";
    }
}
echo "Done. The next page load reinstalls the tables.\n";
'
