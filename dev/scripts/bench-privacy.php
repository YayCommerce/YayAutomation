<?php
/**
 * Privacy lookups, old full scan vs the index, on seeded runs: EXPLAIN and median ms per lookup at each size
 * given (`wp eval-file bench-privacy.php 50000 200000`), then the write cost of indexing. Removes its rows.
 * Run through bench-privacy.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.Security.EscapeOutput.OutputNotEscaped

require_once __DIR__ . '/lib/privacy-old-scan.php';

use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\Schema;

global $wpdb;
const BENCH_WORKFLOW  = 999996;
const BENCH_CUSTOMERS = 20000;
// One shopper in every this many runs, so pages 1 and 2 of 50 both have rows at 50k.
const BENCH_HEAVY_EVERY = 400;
const BENCH_HEAVY       = 'heavy@bench.test';

$sizes = array_map( 'intval', $args ? $args : [ 50000, 200000 ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
sort( $sizes );
$runs_table  = RunStore::table();
$steps_table = RunStepStore::table();
$index_table = Schema::table( Schema::RUN_EMAILS );

$cleanup = function () use ( $wpdb, $runs_table, $steps_table, $index_table ) {
    $wpdb->query( $wpdb->prepare( 'DELETE e FROM %i e INNER JOIN %i r ON r.id = e.run_id WHERE r.workflow_id = %d', $index_table, $runs_table, BENCH_WORKFLOW ) );
    $wpdb->query( $wpdb->prepare( 'DELETE s FROM %i s INNER JOIN %i r ON r.id = s.run_id WHERE r.workflow_id = %d', $steps_table, $runs_table, BENCH_WORKFLOW ) );
    $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE workflow_id = %d', $runs_table, BENCH_WORKFLOW ) );
};
$cleanup();
register_shutdown_function( $cleanup );

$filler   = str_repeat( 'Thanks for shopping with us. ', 12 );
$statuses = array_merge( array_fill( 0, 14, 'completed' ), [ 'failed', 'failed', 'waiting', 'waiting', 'skipped', 'cancelled' ] );

/** Seeds runs up to `$total`, with 3–6 steps each and the index rows the stores would write. */
$seed = function ( int $total ) use ( $wpdb, $runs_table, $steps_table, $index_table, $filler, $statuses ) {
    $have = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE workflow_id = %d', $runs_table, BENCH_WORKFLOW ) );
    $now  = gmdate( 'Y-m-d H:i:s' );
    for ( $from = $have; $from < $total; $from += 500 ) {
        $rows   = [];
        $emails = [];
        for ( $i = $from; $i < min( $total, $from + 500 ); $i++ ) {
            $email    = 0 === $i % BENCH_HEAVY_EVERY ? BENCH_HEAVY : 'c' . ( $i % BENCH_CUSTOMERS ) . '@bench.test';
            $subject  = ( $i % 3 ? 'order:' : 'cart:' ) . ( 1000000 + $i );
            $scope    = wp_json_encode(
                [
                    'trigger' => [
                        'order'    => [ '__ref' => $subject ],
                        'customer' => [ '__ref' => 'customer:' . $subject ],
                    ],
                    'steps'   => [
                        'n2' => [
                            'to'      => $email,
                            'subject' => 'Your order is on its way',
                            'sent'    => true,
                        ],
                    ],
                    'run'     => [ 'id' => $i ],
                ]
            );
            $rows[]   = $wpdb->prepare( '(%d, 1, %s, %s, %s, %s, %s, %s)', BENCH_WORKFLOW, 'order_created', $subject, $statuses[ $i % count( $statuses ) ], $scope, $now, $now );
            $emails[] = $email;
        }
        $wpdb->query( "INSERT INTO {$runs_table} (workflow_id, version_id, trigger_id, subject, status, scope, started_at, finished_at) VALUES " . implode( ',', $rows ) );
        $first = (int) $wpdb->insert_id;
        $ids   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE id >= %d AND workflow_id = %d ORDER BY id LIMIT %d', $runs_table, $first, BENCH_WORKFLOW, count( $rows ) ) ) );
        $steps = [];
        $index = [];
        foreach ( $ids as $n => $id ) {
            $email = $emails[ $n ];
            $count = 3 + $id % 4;
            for ( $k = 0; $k < $count; $k++ ) {
                $error   = 0 === ( $id + $k ) % 33 ? "Could not send to <{$email}>" : null;
                $steps[] = $wpdb->prepare(
                    '(%d, %s, %s, %s, %s, %s, %s)',
                    $id,
                    'n' . ( $k + 2 ),
                    $error ? 'failed' : 'completed',
                    wp_json_encode(
                        [
                            'to'      => '{{ customer.email }}',
                            'subject' => 'Your order',
                            'html'    => "<p>{$filler}</p>",
                        ]
                    ),
                    wp_json_encode(
                        [
                            'to'         => $email,
                            'message_id' => "<{$id}.{$k}@bench.test>",
                        ]
                    ),
                    (string) $error,
                    $now
                );
            }
            $index[] = $wpdb->prepare( '(%d, %s)', $id, $email );
        }
        $wpdb->query( "INSERT INTO {$steps_table} (run_id, step_id, status, input, output, error, started_at) VALUES " . implode( ',', $steps ) );
        $wpdb->query( "INSERT IGNORE INTO {$index_table} (run_id, email) VALUES " . implode( ',', $index ) );
    }
    $wpdb->query( "ANALYZE TABLE {$runs_table}, {$steps_table}, {$index_table}" );
};

/** Median ms of `$reps` calls, and EXPLAIN of the last query. */
$measure   = function ( callable $call, int $reps ) use ( $wpdb ) {
    $times = [];
    for ( $i = 0; $i < $reps; $i++ ) {
        $start = microtime( true );
        $call();
        $times[] = ( microtime( true ) - $start ) * 1000;
    }
    sort( $times );
    $plan = (array) $wpdb->get_results( 'EXPLAIN ' . $wpdb->last_query, ARRAY_A );
    return [ $times[ intdiv( count( $times ), 2 ) ], $plan ];
};
$full_scan = fn( array $plan ) => (bool) array_filter( $plan, fn( $row ) => 'ALL' === $row['type'] && '<' !== substr( (string) $row['table'], 0, 1 ) );
$explain   = fn( array $plan ) => implode( '; ', array_map( fn( $row ) => "{$row['table']} {$row['type']} " . ( $row['key'] ? $row['key'] : '-' ) . " rows={$row['rows']}", $plan ) );

$failed = false;
foreach ( $sizes as $size ) {
    $seed( $size );
    $subjects = $wpdb->get_col( $wpdb->prepare( 'SELECT r.subject FROM %i r INNER JOIN %i e ON e.run_id = r.id WHERE e.email = %s', $runs_table, $index_table, BENCH_HEAVY ) );
    $lookups  = [
        'for_export p1'            => [ fn() => RunStore::for_export( BENCH_HEAVY, $subjects, 50, 0 ), fn() => yaymatik_old_for_export( BENCH_HEAVY, $subjects, 50, 0 ) ],
        'for_export p2'            => [ fn() => RunStore::for_export( BENCH_HEAVY, $subjects, 50, 50 ), fn() => yaymatik_old_for_export( BENCH_HEAVY, $subjects, 50, 50 ) ],
        'ids_mentioning'           => [ fn() => RunStore::ids_mentioning( BENCH_HEAVY, $subjects, 50 ), fn() => yaymatik_old_ids_mentioning( BENCH_HEAVY, $subjects, 50 ) ],
        'in_progress_mentioning'   => [ fn() => RunStore::in_progress_mentioning( BENCH_HEAVY, $subjects ), fn() => yaymatik_old_in_progress_mentioning( BENCH_HEAVY, $subjects ) ],
        'waiting_ids_for_subjects' => [
            fn() => RunStore::waiting_ids_for_subjects( $subjects ),
            fn() => $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i IGNORE INDEX (subject) WHERE status = %s AND subject IN (' . Schema::in( count( $subjects ), '%s' ) . ')', array_merge( [ $runs_table, RunStore::STATUS_WAITING ], $subjects ) ) ),
        ],
    ];
    echo "\n## {$size} runs (" . count( $subjects ) . " runs of the measured shopper)\n\n";
    echo "| Lookup | Old ms | New ms | New plan | Pass |\n|---|---|---|---|---|\n";
    foreach ( $lookups as $name => list( $new, $old ) ) {
        list( $old_ms )        = $measure( $old, 1 );
        list( $new_ms, $plan ) = $measure( $new, 5 );
        $pass                  = $new_ms < 50 && ! $full_scan( $plan );
        $failed                = $failed || ! $pass;
        printf( "| %s | %.1f | %.1f | %s | %s |\n", $name, $old_ms, $new_ms, $explain( $plan ), $pass ? 'PASS' : 'FAIL' );
    }
}

// Write cost: a run with 5 steps whose values hold an email (one index write each) vs none (no index query).
$write = function ( string $to ) {
    $id = RunStore::create(
        [
            'workflow_id' => BENCH_WORKFLOW,
            'version_id'  => 1,
            'scope'       => [ 'steps' => [ 'n2' => [ 'to' => $to ] ] ],
        ]
    );
    for ( $k = 0; $k < 5; $k++ ) {
        RunStepStore::finish( RunStepStore::start( $id, "n{$k}", [ 'to' => $to ] ), RunStepStore::STATUS_COMPLETED, [ 'to' => $to ] );
    }
    RunStore::save(
        $id,
        [
            'scope'  => [ 'steps' => [ 'n2' => [ 'to' => $to ] ] ],
            'status' => RunStore::STATUS_COMPLETED,
        ]
    );
};
$cost  = [];
foreach ( [
    'with an email' => 'writer@bench.test',
    'without'       => '{{ customer.email }}',
] as $label => $to ) {
    $start = microtime( true );
    for ( $i = 0; $i < 200; $i++ ) {
        $write( $to );
    }
    $cost[ $label ] = ( microtime( true ) - $start ) * 1000 / 200;
}
printf( "\n## Write cost (a run + 5 steps, 13 writes)\n\n| Values | ms per run |\n|---|---|\n| with an email (indexed) | %.2f |\n| without (no index query) | %.2f |\n", $cost['with an email'], $cost['without'] );

$cleanup();
echo $failed ? "\nFAIL: a lookup was over 50 ms or scanned a whole table.\n" : "\nAll lookups PASS.\n";
exit( $failed ? 1 : 0 );
