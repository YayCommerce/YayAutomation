<?php
/**
 * Shared by the check scripts: runs one workflow for an event right away, through the Dispatcher
 * (loop and rate checks, version, refs; no claim, no match) and the Worker, as Action Scheduler would.
 *
 * @package YayMatik
 */

use YayMatik\Workflow\Core\Runtime\Runtime;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Core\Storage\WorkflowStore;

/**
 * Runs a workflow on an event: the run row afterwards, with its `steps`, or null when no run started; both are deleted.
 *
 * @param int           $workflow_id
 * @param string        $trigger_id
 * @param array         $event
 * @param callable|null $then        fn( int $run_id ), after the run and before its row is read.
 */
function yaymatik_check_run( int $workflow_id, string $trigger_id, array $event, ?callable $then = null ): ?array {
    $workflow = WorkflowStore::find( $workflow_id );
    $trigger  = StepRegistry::get( $trigger_id );
    if ( ! $workflow || ! $trigger instanceof YayMatik\Workflow\Core\Steps\Trigger || ! Runtime::worker() ) {
        fwrite( STDERR, "Workflow {$workflow_id} or trigger {$trigger_id} not found, or WooCommerce is off.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        return null;
    }
    $run_id = Runtime::dispatcher()->start_run( $workflow, $trigger, $event );
    if ( ! $run_id ) {
        return null;
    }
    Runtime::worker()->run( $run_id );
    if ( $then ) {
        $then( $run_id );
    }
    $run = YayMatik\Workflow\Core\Storage\RunStore::find( $run_id );
    if ( $run ) {
        $run['steps'] = YayMatik\Workflow\Core\Storage\RunStepStore::for_run( $run_id );
    }
    // Leave no rows behind.
    global $wpdb;
    $wpdb->delete( YayMatik\Workflow\Core\Storage\RunStepStore::table(), [ 'run_id' => $run_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->delete( YayMatik\Workflow\Core\Storage\RunStore::table(), [ 'id' => $run_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
    foreach ( WorkflowStore::all() as $workflow ) {
        $name = (string) $workflow['name'];
        if ( 0 !== strpos( $name, $prefix ) || '(temporary)' !== substr( $name, -11 ) ) {
            continue;
        }
        $id = (int) $workflow['id'];
        WorkflowStore::deactivate( $id );
        WorkflowStore::delete( $id );
        if ( WorkflowStore::find( $id ) ) {
            $left[] = $id;
        }
    }
    return $left;
}

/**
 * Removes a check script's runs, with their child runs (by root run), run_steps rows and jobs.
 *
 * @param int   $since        Only runs with a higher id.
 * @param int[] $workflow_ids Runs of these workflows, or of the orders below.
 * @param int[] $order_ids    Order ids. Both lists empty: every run after `$since`.
 * @return array{runs: int, jobs: int} What is still there afterwards (should be 0 each).
 */
function yaymatik_check_remove_runs( int $since, array $workflow_ids = [], array $order_ids = [] ): array {
    // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    global $wpdb;
    $runs  = YayMatik\Workflow\Core\Storage\RunStore::table();
    $steps = YayMatik\Workflow\Core\Storage\RunStepStore::table();
    $queue = YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue::class;

    $where = $wpdb->prepare( 'id > %d', $since );
    $match = [];
    if ( $workflow_ids ) {
        $match[] = 'workflow_id IN (' . implode( ',', array_map( 'intval', $workflow_ids ) ) . ')';
    }
    if ( $order_ids ) {
        $subjects = array_map( fn( $id ) => "'order:" . (int) $id . "'", $order_ids );
        $match[]  = 'subject IN (' . implode( ',', $subjects ) . ')';
    }
    if ( $match ) {
        $where .= ' AND (' . implode( ' OR ', $match ) . ')';
    }

    $roots = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT root_run_id FROM {$runs} WHERE {$where}" ) );
    $ids   = $roots ? array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$runs} WHERE root_run_id IN (" . implode( ',', $roots ) . ')' ) ) : [];
    foreach ( $ids as $id ) {
        as_unschedule_all_actions( $queue::HOOK, [ $id ], $queue::GROUP );
        as_unschedule_all_actions( $queue::RECOVER_HOOK, [ $id ], $queue::GROUP );
    }
    if ( $ids ) {
        $wpdb->query( "DELETE FROM {$steps} WHERE run_id IN (" . implode( ',', $ids ) . ')' );
        $wpdb->query( "DELETE FROM {$runs} WHERE id IN (" . implode( ',', $ids ) . ')' );
    }

    $jobs = 0;
    foreach ( $ids as $id ) {
        foreach ( [ $queue::HOOK, $queue::RECOVER_HOOK ] as $hook ) {
            $jobs += count(
                as_get_scheduled_actions(
                    [
                        'hook'   => $hook,
                        'args'   => [ $id ],
                        'group'  => $queue::GROUP,
                        'status' => 'pending',
                    ],
                    'ids'
                )
            );
        }
    }
    return [
        'runs' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$runs} WHERE {$where}" ),
        'jobs' => $jobs,
    ];
    // phpcs:enable
}
