#!/bin/bash
# Delete all workflows, versions, runs, carts and settings on the local site (wp-cli); keeps unsubscribes. --yes skips the prompt.
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
command -v wp >/dev/null || { echo "Missing: wp-cli"; exit 1; }

if [[ $1 != --yes ]]; then
  read -r -p "Delete every YayMatik workflow, version, run, cart and setting on this site? Type yes: " answer
  [[ $answer == yes ]] || { echo "Cancelled."; exit 1; }
fi

# The plugin stays unloaded, or its installer would recreate the tables first. Also clears what dev builds
# before the workflows table left behind: the yaymatik_wf posts and the versions table.
wp ${WP_PATH:+--path="$WP_PATH"} --skip-plugins="$(basename "$PWD")" eval '
global $wpdb;
$meta = $wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = '\''yaymatik_wf'\''" );
$posts = $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = '\''yaymatik_wf'\''" );
echo "Workflow posts: {$posts} (meta rows: {$meta})\n";
foreach ( [ "workflows", "versions", "workflow_versions", "runs", "run_steps", "carts" ] as $name ) {
    $table = $wpdb->prefix . "yaymatik_" . $name;
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $table ) ) ) === $table ) {
        $wpdb->query( "DROP TABLE {$table}" );
        echo "Dropped {$table}\n";
    }
}
foreach ( [ "yaymatik_db_version", "yaymatik_settings", "yaymatik_cart_secret", "yaymatik_cleanup_checked_at" ] as $option ) {
    echo delete_option( $option ) ? "Deleted option {$option}\n" : "";
}
if ( function_exists( "as_unschedule_all_actions" ) ) {
    foreach ( [ "yaymatik_run", "yaymatik_recover_run", "yaymatik_cleanup" ] as $hook ) {
        $pending = count( as_get_scheduled_actions( [ "hook" => $hook, "status" => "pending", "per_page" => -1 ], "ids" ) );
        as_unschedule_all_actions( $hook );
        echo $pending ? "Unscheduled {$pending} {$hook} actions\n" : "";
    }
}
echo "Done. The next page load reinstalls the tables.\n";
'
