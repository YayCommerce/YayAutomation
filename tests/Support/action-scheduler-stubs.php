<?php
/**
 * Action Scheduler functions over `$GLOBALS['yay_test_as']`: a list of `hook`, `args`, `group`, `at`, `status`.
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
            // Null args and an empty group: any, as in Action Scheduler.
            if ( $job['hook'] === $query['hook'] && ( null === $query['args'] || $job['args'] === $query['args'] ) && ( '' === $query['group'] || $job['group'] === $query['group'] ) && in_array( $job['status'], (array) $query['status'], true ) ) {
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

    // Only the call without args or group, which cancels every action of the hook.
    function as_unschedule_all_actions( $hook ) {
        foreach ( $GLOBALS['yay_test_as'] ?? [] as $i => $job ) {
            if ( $job['hook'] === $hook && 'pending' === $job['status'] ) {
                $GLOBALS['yay_test_as'][ $i ]['status'] = 'canceled';
            }
        }
    }
}//end if
