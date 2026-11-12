#!/bin/bash
# Read-only probe of staging's MySQL 8 (SELECTs only): the privacy index SQL, GET_LOCK / RELEASE_LOCK, the user meta key collation. Staging only.
#   ./run.sh probe-mysql --staging
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
# wp-env and the local site run MariaDB; the probe exists for staging's MySQL.
[[ $1 == --staging ]] || { echo "Refused: probe-mysql runs on --staging only."; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" || exit 1

"${WP[@]}" eval '
global $wpdb;
$fails = 0;
$ok    = function ( $pass, $what ) use ( &$fails ) {
    echo ( $pass ? "PASS " : "FAIL " ), $what, "\n";
    $fails += $pass ? 0 : 1;
};
echo "Server: ", $wpdb->get_var( "SELECT VERSION()" ), "\n";

// The privacy index: the SELECT half of RunStore::index_emails(), and the lookups once the site is at DB 8.
$pairs = $wpdb->get_results( $wpdb->prepare( "SELECT r.run_id, e.email FROM (SELECT %d AS run_id) r CROSS JOIN (SELECT %s AS email UNION ALL SELECT %s AS email) e", 7, "a@x.test", "b@x.test" ), ARRAY_A );
$ok( 2 === count( (array) $pairs ) && "" === $wpdb->last_error, "index rows: derived run ids cross joined with a UNION ALL of emails" );
$emails = $wpdb->prefix . "yaymatik_run_emails";
if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $emails ) ) ) === $emails ) {
    $probe = "probe-" . wp_generate_password( 8, false ) . "@x.test";
    $ok( [] === \YayMatik\Workflow\Core\Storage\RunStore::for_export( $probe, [ "cart:0" ], 50, 50 ) && "" === $wpdb->last_error, "for_export: UNION of the index and subjects, page 2" );
    $ok( [] === \YayMatik\Workflow\Core\Storage\RunStore::ids_mentioning( $probe, [ "cart:0" ], 50 ) && "" === $wpdb->last_error, "ids_mentioning" );
    $ok( false === \YayMatik\Workflow\Core\Storage\RunStore::in_progress_mentioning( $probe, [] ) && "" === $wpdb->last_error, "in_progress_mentioning" );
} else {
    echo "INFO no {$emails} yet (site before DB 8): lookups not probed\n";
}

$lock = "yaymatik_probe_" . wp_generate_password( 8, false );
$ok( "1" === $wpdb->get_var( $wpdb->prepare( "SELECT GET_LOCK( %s, 1 )", $lock ) ), "GET_LOCK taken" );
$ok( "1" === $wpdb->get_var( $wpdb->prepare( "SELECT IS_USED_LOCK( %s ) = CONNECTION_ID()", $lock ) ), "lock held by this connection" );
$ok( "1" === $wpdb->get_var( $wpdb->prepare( "SELECT RELEASE_LOCK( %s )", $lock ) ), "RELEASE_LOCK released" );

// MetaKeys::user_collides() asks the meta_key collation which stored keys a write reaches.
$column = $wpdb->get_row( "SHOW FULL COLUMNS FROM {$wpdb->usermeta} LIKE \"meta_key\"", ARRAY_A );
echo "usermeta.meta_key collation: ", $column["Collation"], "\n";
$key = $wpdb->base_prefix . "capabilities";
foreach ( [ strtoupper( $key ), $wpdb->base_prefix . "capabilitiés" ] as $spelling ) {
    $same = $wpdb->get_var( $wpdb->prepare( "SELECT %s = %s COLLATE " . $column["Collation"], $spelling, $key ) );
    echo "INFO \"{$spelling}\" = \"{$key}\" under the column collation: ", "1" === $same ? "yes" : "no", "\n";
}
$user = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 1", $key ) );
$ok( $user && \YayMatik\Workflow\Core\Data\MetaKeys::user_collides( $user, strtoupper( $key ) ), "user_collides() sees " . strtoupper( $key ) . " reach the {$key} row of user {$user}" );

echo $fails ? "$fails failed\n" : "All passed\n";
exit( $fails ? 1 : 0 );
'
