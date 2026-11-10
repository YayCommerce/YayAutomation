#!/bin/bash
# Read-only probe of staging's MySQL 8 (SELECTs only): the erase REGEXP, GET_LOCK / RELEASE_LOCK, the user meta key collation. Staging only.
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

// The pattern RunStore builds; PHP preg_replace with the same boundaries is the expected answer.
$regexp = new ReflectionMethod( \YayMatik\Workflow\Core\Storage\RunStore::class, "text_regexp" );
$regexp->setAccessible( true );
$email   = "bob.o+1@x.test";
$pattern = $regexp->invoke( null, $email );
$samples = [
    "bob.o+1@x.test"                => true,
    "Sent to bob.o+1@x.test."       => true,
    "(bob.o+1@x.test)"              => true,
    "BOB.O+1@X.TEST failed"         => true,
    "bob.o+1@x.test.au"             => false,
    "abob.o+1@x.test"               => false,
    "bob.o+1@x.tester"              => false,
    "bobXo+1@x.test"                => false,
];
// The erase matches stored rows, so each sample is compared under the collation of runs.error.
$runs      = \YayMatik\Workflow\Core\Storage\RunStore::table();
$collation = $wpdb->get_row( "SHOW FULL COLUMNS FROM {$runs} LIKE \"error\"", ARRAY_A )["Collation"];
echo "{$runs}.error collation: {$collation}\n";
foreach ( $samples as $text => $expected ) {
    $got = (int) $wpdb->get_var( $wpdb->prepare( "SELECT CONVERT( %s USING utf8mb4 ) COLLATE {$collation} REGEXP %s", $text, $pattern ) );
    $ok( ( 1 === $got ) === $expected && "" === $wpdb->last_error, "REGEXP " . ( $expected ? "matches" : "skips" ) . " \"{$text}\"" );
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
