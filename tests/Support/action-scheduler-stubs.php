<?php
/**
 * Action Scheduler functions over `$GLOBALS['yay_test_as']` (list of `hook`, `args`, `group`, `at`,
 * `status`). Loaded only by the tests that need it.
 *
 * @package YayMatik
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
    function as_enqueue_async_action( $hook, $args = [], $group = '' ) {
        return as_schedule_single_action( null, $hook, $args, $group );
    }

    function as_schedule_single_action( $timestamp, $hook, $args = [], $group = '' ) {
        $GLOBALS['yay_test_as'][] = [
            'hook'   => $hook,
            'args'   => $args,
            'group'  => $group,
            'at'     => $timestamp,
            'status' => 'pending',
        ];
        return count( $GLOBALS['yay_test_as'] );
    }

    function as_get_scheduled_actions( $query = [], $return_format = 'OBJECT' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as Action Scheduler.
        $found = [];
        foreach ( $GLOBALS['yay_test_as'] ?? [] as $i => $job ) {
            if ( $job['hook'] === $query['hook'] && $job['args'] === $query['args'] && $job['group'] === $query['group'] && in_array( $job['status'], (array) $query['status'], true ) ) {
                $found[] = $i + 1;
            }
        }
        return $found;
    }

    function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
        $query = [
            'hook'   => $hook,
            'args'   => $args,
            'group'  => $group,
            'status' => [ 'pending', 'in-progress' ],
        ];
        return (bool) as_get_scheduled_actions( $query );
    }
}//end if
