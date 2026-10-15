#!/bin/bash
# Remove pre-rename YayAutomation data from the local site (wp-cli): tables, option, workflows, scheduled actions, order meta.
# Safe to run twice: prints 0 for what is already gone.
#   ./run.sh drop-legacy
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
command -v wp >/dev/null || { echo "Missing: wp-cli"; exit 1; }

wp ${WP_PATH:+--path="$WP_PATH"} eval '
global $wpdb;
$say    = function ( $what, $n ) { printf( "%-34s %d\n", $what, (int) $n ); };
$exists = fn( $t ) => $t === $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $t ) );
$meta   = $wpdb->esc_like( "_yay_automation_" ) . "%";

$tables = $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $wpdb->prefix . "yay_automation_" ) . "%" ) );
foreach ( $tables as $t ) {
    $wpdb->query( "DROP TABLE `$t`" );
}
$say( "tables " . $wpdb->prefix . "yay_automation_*", count( $tables ) );

$say( "option yay_automation_db_version", delete_option( "yay_automation_db_version" ) );

// SQL, not wp_delete_post(): the old post type is no longer registered.
$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = \"yay_automation_wf\"" );
$pm  = 0;
if ( $ids ) {
    $in  = implode( ",", array_map( "intval", $ids ) );
    $pm  = $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($in)" );
    $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ($in)" );
}
$say( "posts yay_automation_wf", count( $ids ) );
$say( "  their postmeta", $pm );

$as = $wpdb->prefix . "actionscheduler_";
if ( $exists( $as . "actions" ) ) {
    $group = (int) $wpdb->get_var( "SELECT group_id FROM {$as}groups WHERE slug = \"yay-automation\"" );
    $where = $wpdb->prepare( "hook IN (%s, %s) OR group_id = %d", "yay_automation_run", "yay_automation_recover_run", $group ?: -1 );
    $logs  = $wpdb->query( "DELETE l FROM {$as}logs l JOIN {$as}actions a ON a.action_id = l.action_id WHERE $where" );
    $say( "AS actions (old hooks + group)", $wpdb->query( "DELETE FROM {$as}actions WHERE $where" ) );
    $say( "  their logs", $logs );
    $say( "AS group yay-automation", $group ? $wpdb->query( "DELETE FROM {$as}groups WHERE group_id = $group" ) : 0 );
}

$say( "order postmeta _yay_automation_*", $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $meta ) ) );
$hpos = $wpdb->prefix . "wc_orders_meta";
$say( "HPOS order meta _yay_automation_*", $exists( $hpos ) ? $wpdb->query( $wpdb->prepare( "DELETE FROM $hpos WHERE meta_key LIKE %s", $meta ) ) : 0 );
'
