<?php
/**
 * End to end: a custom workflow per new trigger (a group condition, then a customer email, caught), and one run through every new action.
 * Required by check-catalog.php, after the triggers and actions checks, whose helpers and cleanup it uses.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

use YayMatik\Workflow\Core\Definition\GraphSchema;
use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\WorkflowStore;

/** Trigger → condition with a group → Send email to the customer, subject "e2e <trigger> <email>". */
$e2e_graph = fn( string $trigger, array $trigger_config ) => GraphSchema::prepare(
    [
        'nodes' => [
            [
                'id'       => 'trigger',
                'kind'     => 'trigger',
                'position' => [
                    'x' => 0,
                    'y' => 0,
                ],
                'data'     => [
                    'block'  => $trigger,
                    'config' => $trigger_config,
                ],
            ],
            [
                'id'       => 'rule',
                'kind'     => 'condition',
                'position' => [
                    'x' => 0,
                    'y' => 100,
                ],
                'data'     => [
                    'block'  => 'condition',
                    'config' => [
                        'match' => 'all',
                        'rules' => [
                            [
                                'field'     => 'customer.email',
                                'operator'  => 'is_not_empty',
                                'value'     => '',
                                'valueType' => 'value',
                            ],
                            [
                                'match' => 'any',
                                'rules' => [
                                    [
                                        'field'     => 'customer.email',
                                        'operator'  => 'contains',
                                        'value'     => 'e2e',
                                        'valueType' => 'value',
                                    ],
                                    [
                                        'field'     => 'customer.first_name',
                                        'operator'  => 'equal',
                                        'value'     => 'nobody-has-this-name',
                                        'valueType' => 'value',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id'       => 'mail',
                'kind'     => 'action',
                'position' => [
                    'x' => 0,
                    'y' => 200,
                ],
                'data'     => [
                    'block'  => 'send_email',
                    'config' => [
                        'to'      => '{{ customer.email }}',
                        'subject' => "e2e {$trigger} {{ customer.email }}",
                        'heading' => 'check-catalog',
                        'message' => '<p>check-catalog</p>',
                    ],
                ],
            ],
        ],
        'edges' => [
            [
                'id'     => 'e1',
                'source' => 'trigger',
                'target' => 'rule',
            ],
            [
                'id'           => 'e2',
                'source'       => 'rule',
                'target'       => 'mail',
                'sourceHandle' => 'then',
            ],
        ],
    ]
);

$e2e_configs = [
    'order_paid'                   => [],
    'order_refunded'               => [],
    'order_note_added'             => [
        'note_type'     => 'any',
        'contains'      => 'e2e',
        'skip_yaymatik' => true,
    ],
    'customer_order_count_reaches' => [ 'count' => 1 ],
    'customer_total_spend_reaches' => [ 'amount' => 10 ],
    'review_posted'                => [],
];
$e2e_flows   = [];
foreach ( $e2e_configs as $trigger => $config ) {
    $e2e_flows[ $trigger ] = $activate_graph( $e2e_graph( $trigger, $config ) );
}
list( $e2e_user, $e2e_email ) = $new_user( 'e2e' . wp_rand( 1000, 9999 ) );
$e2e_order                    = $order(
    [
        'customer_id'   => $e2e_user,
        'billing_email' => $e2e_email,
        'total'         => '50',
    ]
);
$e2e_guest                    = 'e2e-guest' . wp_rand( 1000, 9999 ) . '@' . $domain;

$mails = [];
add_filter( 'pre_wp_mail', $catch, 10, 2 );
$started( array_values( $e2e_flows ), $move( $e2e_order, 'processing' ) );
$started( array_values( $e2e_flows ), fn() => wc_get_order( $e2e_order )->add_order_note( 'e2e note' ) );
$started(
    array_values( $e2e_flows ),
    fn() => wc_create_refund(
        [
            'order_id' => $e2e_order,
            'amount'   => '5',
            'reason'   => 'check-catalog refund',
        ]
    )
);
$started(
    array_values( $e2e_flows ),
    fn() => $review_on(
        [ 'comment_author_email' => $e2e_guest ],
        [ 'rating' => 4 ]
    )
);
foreach ( $e2e_flows as $flow_id ) {
    $work( $runs_of( $flow_id ) );
}
remove_filter( 'pre_wp_mail', $catch );
$e2e_subjects = array_column( $mails, 'subject' );
foreach ( $e2e_flows as $trigger => $flow_id ) {
    $runs     = $runs_of( $flow_id );
    $statuses = array_map( fn( int $run_id ) => RunStore::find( $run_id )['status'], $runs );
    $to       = 'review_posted' === $trigger ? $e2e_guest : $e2e_email;
    $ok( 1 === count( $runs ) && [ 'completed' ] === $statuses && in_array( "e2e {$trigger} {$to}", $e2e_subjects, true ), "e2e {$trigger}: one run, through the group condition, emailed the customer" );
    WorkflowStore::deactivate( $flow_id );
}

// One run through every new action, on a paid order of an account.
$actions_flow  = $activate_graph(
    $chain(
        'order_paid',
        [],
        [
            [
                'meta',
                'update_customer_meta',
                [
                    'key'   => 'check_e2e',
                    'value' => 'yes',
                ],
            ],
            [
                'role',
                'change_customer_role',
                [
                    'mode' => 'add',
                    'role' => 'check_wholesale',
                ],
            ],
            [
                'resend',
                'resend_wc_email',
                [ 'email' => 'customer_processing_order' ],
            ],
            [
                'until',
                'wait_until',
                [
                    'time'     => '03:07',
                    'weekdays' => [],
                ],
            ],
        ]
    )
);
list( $actor ) = $new_user( 'e2e-actor' . wp_rand( 1000, 9999 ) );
$actor_order   = $order( [ 'customer_id' => $actor ] );
$turn_on       = '__return_true';
$started( [ $actions_flow ], $move( $actor_order, 'processing' ) );
// On only for the resend: WooCommerce's own send at the status change stays off.
add_filter( 'woocommerce_email_enabled_customer_processing_order', $turn_on, 20 );
$mails = [];
add_filter( 'pre_wp_mail', $catch, 10, 2 );
$actor_runs = $runs_of( $actions_flow );
$work( $actor_runs );
$waited = RunStore::find( $actor_runs[0] )['status'];
RunStore::save( $actor_runs[0], [ 'wait_until' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
$work( $actor_runs );
remove_filter( 'pre_wp_mail', $catch );
remove_filter( 'woocommerce_email_enabled_customer_processing_order', $turn_on, 20 );
$actor_steps = array_column( RunStepStore::for_run( $actor_runs[0] ), 'status', 'step_id' );
clean_user_cache( $actor );
$ok(
    'waiting' === $waited && 'completed' === RunStore::find( $actor_runs[0] )['status']
        && [ 'completed' ] === array_values( array_unique( array_intersect_key( $actor_steps, array_flip( [ 'meta', 'role', 'resend', 'until' ] ) ) ) )
        && 'yes' === get_user_meta( $actor, 'check_e2e', true ) && in_array( 'check_wholesale', ( new WP_User( $actor ) )->roles, true ) && 1 === count( $mails ),
    'e2e actions: a run goes through every new action, waits, then completes (' . wp_json_encode( $actor_steps ) . ')'
);
WorkflowStore::deactivate( $actions_flow );
