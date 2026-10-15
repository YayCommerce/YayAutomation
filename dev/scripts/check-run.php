<?php
/**
 * Shared by the check scripts: runs one workflow for an event right away, through the Dispatcher
 * (loop and rate checks, version, refs; no claim, no match) and the Worker, as Action Scheduler would.
 *
 * @package YayMatik
 */

use YayMatik\Workflow\Runtime\Runtime;
use YayMatik\Workflow\Steps\Core\StepRegistry;
use YayMatik\Workflow\Storage\WorkflowRepository;

/**
 * @param int    $workflow_id Workflow id.
 * @param string $trigger_id  Trigger step id.
 * @param array  $event       Event args.
 * @return array|null The run row afterwards, with its `steps`; null when no run started. Both are deleted.
 */
function yaymatik_check_run( int $workflow_id, string $trigger_id, array $event ): ?array {
    $workflow = WorkflowRepository::find( $workflow_id );
    $trigger  = StepRegistry::get( $trigger_id );
    if ( ! $workflow || ! $trigger instanceof YayMatik\Workflow\Steps\Core\Trigger || ! Runtime::worker() ) {
        fwrite( STDERR, "Workflow {$workflow_id} or trigger {$trigger_id} not found, or WooCommerce is off.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        return null;
    }
    $run_id = Runtime::dispatcher()->start_run( $workflow, $trigger, $event );
    if ( ! $run_id ) {
        return null;
    }
    Runtime::worker()->run( $run_id );
    $run = YayMatik\Workflow\Storage\RunStore::find( $run_id );
    if ( $run ) {
        $run['steps'] = YayMatik\Workflow\Storage\RunStepStore::for_run( $run_id );
    }
    // Leave no rows behind.
    global $wpdb;
    $wpdb->delete( YayMatik\Workflow\Storage\RunStepStore::table(), [ 'run_id' => $run_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->delete( YayMatik\Workflow\Storage\RunStore::table(), [ 'id' => $run_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return $run;
}

/**
 * Removes the temporary workflows of a check script, e.g. ones an earlier, interrupted run left:
 * turned off first (an active workflow can't be deleted), then deleted, then looked up again.
 *
 * @param string $prefix Name prefix, e.g. `Check runs:`; only names ending in `(temporary)` match.
 * @return int[] Ids still there afterwards (should be none).
 */
function yaymatik_check_remove_workflows( string $prefix ): array {
    $left = [];
    foreach ( WorkflowRepository::all() as $workflow ) {
        $name = (string) $workflow['name'];
        if ( 0 !== strpos( $name, $prefix ) || '(temporary)' !== substr( $name, -11 ) ) {
            continue;
        }
        $id = (int) $workflow['id'];
        WorkflowRepository::deactivate( $id );
        WorkflowRepository::delete( $id );
        if ( WorkflowRepository::find( $id ) ) {
            $left[] = $id;
        }
    }
    return $left;
}
