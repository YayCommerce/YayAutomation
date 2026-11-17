<?php
/**
 * Steps of check-edition.sh, one per call: `wp eval-file … <step>`; the shell switches the edition between them.
 * Ids made in `pro-setup` are kept in an option for the steps after it.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.Security.EscapeOutput.OutputNotEscaped -- a dev check on a wp-env site.

require_once __DIR__ . '/check-run.php';

use YayMatik\Workflow\Core\Definition\ProCatalog;
use YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Core\Runtime\HealthCheck;
use YayMatik\Workflow\Core\Runtime\Runtime;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\WorkflowStore;

const CHECK_STATE = 'yaymatik_check_edition';
const CHECK_NAME  = 'Check edition:';

global $wpdb;
wp_set_current_user( 1 );
$fails = 0;
$ok    = function ( bool $pass, string $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n";
    $fails += $pass ? 0 : 1;
};
$call  = function ( string $method, string $route, ?array $body = null ) {
    $request = new WP_REST_Request( $method, '/yaymatik/v1/' . $route );
    if ( null !== $body ) {
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( wp_json_encode( $body ) );
    }
    return rest_do_request( $request );
};
$node  = fn( string $id, string $kind, string $block, array $config, int $row ) => [
    'id'       => $id,
    'kind'     => $kind,
    'position' => [
        'x' => 0,
        'y' => 170 * $row,
    ],
    'data'     => [
        'block'  => $block,
        'config' => (object) $config,
    ],
];
// Trigger, then each node below the one before; a condition's child hangs from Yes.
$chain = function ( array $nodes ) {
    $edges = [];
    for ( $i = 1; $i < count( $nodes ); $i++ ) {
        $edge = [
            'id'     => "e{$i}",
            'source' => $nodes[ $i - 1 ]['id'],
            'target' => $nodes[ $i ]['id'],
        ];
        if ( 'condition' === $nodes[ $i - 1 ]['kind'] ) {
            $edge['sourceHandle'] = 'then';
        }
        $edges[] = $edge;
    }
    return [
        'nodes' => $nodes,
        'edges' => $edges,
    ];
};
$order     = function () {
    $made = wc_create_order( [ 'status' => 'pending' ] );
    $made->set_billing_email( 'edition@yay-seed.test' );
    $made->update_meta_data( '_check_edition', 'order' );
    $made->save();
    return $made;
};
$new_runs  = function ( int $since, array $workflow_ids ) use ( $wpdb ) {
    Runtime::dispatcher()->flush();
    $ids = $wpdb->get_results( $wpdb->prepare( 'SELECT id, workflow_id FROM ' . RunStore::table() . ' WHERE id > %d', $since ), ARRAY_A );
    return array_values( array_filter( $ids, fn( array $run ) => in_array( (int) $run['workflow_id'], $workflow_ids, true ) ) );
};
$max_run   = fn() => (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . RunStore::table() );
$state     = get_option( CHECK_STATE, [] );
$using_pro = fn() => array_slice( $state['workflows'] ?? [], 0, 4, true );

switch ( $args[0] ?? '' ) {
    case 'pro-setup':
        $ok( ! yaymatik_check_remove_workflows( CHECK_NAME ) && ! array_filter( ProCatalog::missing() ), 'setup: YayMatik Pro, no temporary workflows left' );
        $note       = fn( string $id, string $text, int $row ) => $node( $id, 'action', 'add_order_note', [ 'note' => $text ], $row );
        $created    = $node( 'trigger', 'trigger', 'order_created', [], 0 );
        $group      = [
            'match' => 'all',
            'rules' => [
                [
                    'match' => 'any',
                    'rules' => [
                        [
                            'field'     => 'order.total',
                            'operator'  => 'greater_or_equal',
                            'value'     => '0',
                            'valueType' => 'value',
                        ],
                    ],
                ],
            ],
        ];
        $graphs     = [
            'pro trigger'   => $chain( [ $node( 'trigger', 'trigger', 'customer_order_count_reaches', [ 'count' => 99 ], 0 ), $note( 'note', 'Check edition: milestone', 1 ) ] ),
            'pro action'    => $chain(
                [
                    $created,
                    $node(
                        'meta',
                        'action',
                        'update_order_meta',
                        [
                            'key'   => '_check_edition',
                            'value' => 'pro action',
                        ],
                        1
                    ),
                ]
            ),
            'pro condition' => $chain( [ $created, $node( 'c', 'condition', 'condition', $group, 1 ), $note( 'note', 'Check edition: group', 2 ) ] ),
            'pro merge tag' => $chain( [ $created, $note( 'note', 'Check edition: spent {{ customer.total_spent }}', 1 ) ] ),
            'waiting'       => $chain(
                [
                    $created,
                    $node(
                        'wait',
                        'action',
                        'wait',
                        [
                            'delay' => [
                                'value' => 1,
                                'unit'  => 'hours',
                            ],
                        ],
                        1
                    ),
                    $node(
                        'meta',
                        'action',
                        'update_order_meta',
                        [
                            'key'   => '_check_edition',
                            'value' => 'after wait',
                        ],
                        2
                    ),
                ]
            ),
        ];
        $order_id   = $order()->get_id();
        $workflows  = [];
        foreach ( $graphs as $name => $graph ) {
            $made                = $call(
                'POST',
                'workflows',
                [
                    'name'  => CHECK_NAME . " {$name} (temporary)",
                    'graph' => $graph,
                ]
            )->get_data();
            $id                  = (int) $made['id'];
            $workflows[ $name ]  = $id;
            $activated           = $call( 'POST', "workflows/{$id}/activate" );
            $ok( 200 === $activated->get_status() && WorkflowStore::is_active( $id ), "pro: {$name} activates" );
        }
        $run_id = Runtime::dispatcher()->start_run( WorkflowStore::find( $workflows['waiting'] ), StepRegistry::get( 'order_created' ), [ 'order_id' => $order_id ] );
        as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $run_id ], ActionSchedulerQueue::GROUP );
        Runtime::worker()->run( $run_id );
        $ok( RunStore::STATUS_WAITING === RunStore::find( $run_id )['status'], 'pro: a run waits on its Wait step' );
        update_option(
            CHECK_STATE,
            [
                'workflows' => $workflows,
                'run'       => $run_id,
                'graphs'    => array_map( fn( int $id ) => WorkflowStore::find( $id )['graph'], $workflows ),
                'orders'    => [ $order_id ],
                'since'     => $run_id - 1,
            ],
            false
        );
        break;

    case 'lite':
        $missing = ProCatalog::missing();
        $ok( $missing['steps'] && $missing['fields'] && $missing['features'], 'lite: Pro\'s steps, fields and condition features are missing' );

        $since = $max_run();
        $made  = $order();
        $state['orders'][] = $made->get_id();
        update_option( CHECK_STATE, $state, false );
        $ok( [] === $new_runs( $since, array_values( $using_pro() ) ), 'lite: a new order starts none of the workflows using Pro' );

        $health = array_column( HealthCheck::run(), null, 'id' )['needs_pro'];
        $listed = array_map( 'intval', array_column( $health['workflows'], 'id' ) );
        $ok( 'problem' === $health['status'] && ! array_diff( $state['workflows'], $listed ), 'lite: health check lists the 5 active workflows that need Pro' );

        // The waiting run's time is up: it goes on to Update order custom field, which this site can't run.
        $wpdb->update( RunStore::table(), [ 'wait_until' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ], [ 'id' => $state['run'] ] );
        as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ (int) $state['run'] ], ActionSchedulerQueue::GROUP );
        Runtime::worker()->run( (int) $state['run'] );
        $run = RunStore::find( (int) $state['run'] );
        $ok( RunStore::STATUS_CANCELLED === $run['status'] && 'Needs YayMatik Pro.' === $run['error'], 'lite: the waiting run stops before the Pro step, "Needs YayMatik Pro."' );
        $ok( 'after wait' !== wc_get_order( $state['orders'][0] )->get_meta( '_check_edition' ), 'lite: the Pro step never ran' );

        foreach ( $using_pro() as $name => $id ) {
            $call( 'POST', "workflows/{$id}/deactivate" );
            $saved    = $call( 'PUT', "workflows/{$id}", [ 'graph' => $state['graphs'][ $name ] ] );
            $codes    = array_column( $call( 'POST', "workflows/{$id}/activate" )->get_data()['data']['errors'] ?? [], 'code' );
            $kept     = WorkflowStore::find( $id )['graph'];
            $same     = wp_json_encode( $kept['nodes'] ) === wp_json_encode( $state['graphs'][ $name ]['nodes'] );
            $ok( 200 === $saved->get_status() && $same, "lite: {$name} saves, its Pro settings kept as they were" );
            $ok( in_array( 'needs_pro', $codes, true ) && ! WorkflowStore::is_active( $id ), "lite: {$name} doesn't activate (needs_pro)" );
        }
        break;

    case 'pro-again':
        $ok( ! array_filter( ProCatalog::missing() ), 'pro again: nothing missing' );
        foreach ( $using_pro() as $name => $id ) {
            $ok( 200 === $call( 'POST', "workflows/{$id}/activate" )->get_status() && WorkflowStore::is_active( $id ), "pro again: {$name} activates" );
        }
        $since = $max_run();
        $made  = $order();
        $state['orders'][] = $made->get_id();
        update_option( CHECK_STATE, $state, false );
        $runs = $new_runs( $since, array_values( array_slice( $using_pro(), 1, 3 ) ) );
        foreach ( $runs as $run ) {
            as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ (int) $run['id'] ], ActionSchedulerQueue::GROUP );
            Runtime::worker()->run( (int) $run['id'] );
        }
        $statuses = array_map( fn( array $run ) => RunStore::find( (int) $run['id'] )['status'], $runs );
        $notes    = wp_list_pluck( wc_get_order_notes( [ 'order_id' => $made->get_id() ] ), 'content' );
        $ok( 3 === count( $runs ) && [ RunStore::STATUS_COMPLETED ] === array_values( array_unique( $statuses ) ), 'pro again: a new order runs the Pro action, condition and merge tag workflows to the end' );
        $ok( 'pro action' === wc_get_order( $made->get_id() )->get_meta( '_check_edition' ) && in_array( 'Check edition: group', $notes, true ), 'pro again: the custom field is set and the group still matches' );
        break;

    case 'cleanup':
        $left = yaymatik_check_remove_workflows( CHECK_NAME );
        $runs = yaymatik_check_remove_runs( (int) ( $state['since'] ?? 0 ), array_values( $state['workflows'] ?? [] ), $state['orders'] ?? [] );
        foreach ( $state['orders'] ?? [] as $id ) {
            $made = wc_get_order( $id );
            if ( $made ) {
                $made->delete( true );
            }
        }
        delete_option( CHECK_STATE );
        $ok( ! $left && ! $runs['runs'] && ! $runs['jobs'], 'cleanup: temporary workflows, runs, jobs and orders removed' );
        break;

    default:
        WP_CLI::error( 'Unknown step: ' . ( $args[0] ?? '' ) );
}

if ( $fails ) {
    WP_CLI::halt( 1 );
}
