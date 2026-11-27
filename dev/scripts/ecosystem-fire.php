<?php
/**
 * The two ecosystem-fire.sh phases: `fire <trigger>` prints the last run id before it fires;
 * `report <trigger> <id>` lists the runs started after it.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- wp eval-file runs in global scope; dev script.

use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\WorkflowStore;
use YayMatik\Workflow\Extension\ExtensionTrigger;

$phase   = $args[0] ?? '';
$trigger = StepRegistry::get( (string) ( $args[1] ?? '' ) );
if ( ! $trigger instanceof ExtensionTrigger ) {
    $ids = array_keys( array_filter( StepRegistry::triggers(), fn( $step ) => $step instanceof ExtensionTrigger ) );
    fwrite( STDERR, 'Not a scaffold trigger. Known: ' . ( $ids ? implode( ', ', $ids ) : 'none (is YAYMATIK_IS_DEVELOPMENT on?)' ) . "\n" );
    exit( 1 );
}

global $wpdb;
if ( 'fire' === $phase ) {
    $payload = $trigger->sample_facts();
    foreach ( $trigger->provides() as $item ) {
        if ( 'customer' === $item ) {
            $users              = get_users(
                [
                    'role__in' => [ 'customer', 'administrator' ],
                    'orderby'  => 'ID',
                    'order'    => 'DESC',
                    'number'   => 1,
                    'fields'   => 'ID',
                ]
            );
            $payload['user_id'] = (int) ( $users[0] ?? 0 );
        } else {
            // A scaffold's own data item: its fake adapter loads id 1.
            $payload[ $item . '_id' ] = '1';
        }
    }
    if ( ! WorkflowStore::active_for_trigger( $trigger->id() ) ) {
        fwrite( STDERR, "No active workflow starts on {$trigger->id()}: use one of its templates and activate it.\n" );
        exit( 1 );
    }
    fwrite( STDERR, "Fired {$trigger->id()} with " . wp_json_encode( $payload ) . "\n" );
    echo (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', RunStore::table() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    do_action( 'yaymatik_fire', $trigger->id(), $payload );
    return;
}

$runs = $wpdb->get_results( $wpdb->prepare( 'SELECT id, workflow_id, status, error FROM %i WHERE id > %d AND trigger_id = %s ORDER BY id', RunStore::table(), (int) ( $args[2] ?? 0 ), $trigger->id() ), ARRAY_A );
if ( ! $runs ) {
    echo "FAIL no run started\n";
    exit( 1 );
}
$failed = false;
foreach ( $runs as $run ) {
    $failed = $failed || RunStore::STATUS_FAILED === $run['status'];
    echo esc_html( "Run #{$run['id']} (workflow #{$run['workflow_id']}): {$run['status']}" . ( $run['error'] ? " - {$run['error']}" : '' ) ), "\n";
    foreach ( RunStepStore::for_run( (int) $run['id'] ) as $step ) {
        echo esc_html( "  {$step['step_id']}: {$step['status']}" . ( $step['error'] ? " - {$step['error']}" : '' ) ), "\n";
    }
}
exit( $failed ? 1 : 0 );
