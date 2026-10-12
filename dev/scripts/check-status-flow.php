<?php
/**
 * Runs a temporary "Order status changed → Change order status → Update order meta → Add order
 * note" workflow on real orders and prints what it did, as JSON. Mail is captured, not sent.
 * Afterwards each order gets its status back, the meta and the new notes are removed, and the
 * workflow is deleted (before shutdown, so the restore can't queue a run). Run through
 * check-status-flow.sh.
 *
 * Args: order ids.
 *
 * @package YayAutomation
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- wp eval-file passes $args.

require_once __DIR__ . '/check-run.php';

const META_KEY      = '_yay_automation_check';
const TARGET_STATUS = 'failed';
// From on-hold and back: no paid / completed dates, no stock change.

wp_set_current_user( 1 );
$mails = [];
add_filter(
    'pre_wp_mail',
    function ( $short_circuit, $atts ) use ( &$mails ) {
        $mails[] = [
            'to'      => $atts['to'],
            'subject' => $atts['subject'],
        ];
        return true;
    },
    10,
    2
);

$call = function ( string $method, string $route, ?array $body = null ) {
    $request = new WP_REST_Request( $method, '/yay-automation/v1/' . $route );
    if ( null !== $body ) {
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( wp_json_encode( $body ) );
    }
    return rest_do_request( $request )->get_data();
};

$node  = fn( string $id, string $block, array $config, int $row ) => [
    'id'       => $id,
    'kind'     => 'order_status_changed' === $block ? 'trigger' : 'action',
    'position' => [
        'x' => 0,
        'y' => 170 * $row,
    ],
    'data'     => [
        'block'  => $block,
        'config' => (object) $config,
    ],
];
$graph = [
    'nodes' => [
        $node( 'trigger', 'order_status_changed', [], 0 ),
        $node( 'status', 'change_order_status', [ 'status' => TARGET_STATUS ], 1 ),
        $node(
            'meta',
            'update_order_meta',
            [
                'key'   => META_KEY,
                'value' => 'Order {{ order.number }} for {{ customer.email }}',
            ],
            2
        ),
        $node(
            'note',
            'add_order_note',
            [
                'note' => 'Check: order {{ order.number }} is {{ order.status }}, total {{ order.total }}',
                'type' => 'private',
            ],
            3
        ),
    ],
    'edges' => [
        [
            'id'     => 'e1',
            'source' => 'trigger',
            'target' => 'status',
        ],
        [
            'id'     => 'e2',
            'source' => 'status',
            'target' => 'meta',
        ],
        [
            'id'     => 'e3',
            'source' => 'meta',
            'target' => 'note',
        ],
    ],
];

// A workflow left by an earlier, interrupted run would also act on these orders.
$left = yay_automation_check_remove_workflows( 'Check status flow' );
if ( $left ) {
    fwrite( STDERR, 'Could not remove leftover workflows: ' . implode( ', ', $left ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
    exit( 1 );
}

$out = [];
try {
    $workflow = $call(
        'POST',
        'workflows',
        [
            'name'  => 'Check status flow (temporary)',
            'graph' => $graph,
        ]
    );
    $id       = (int) $workflow['id'];
    $call( 'POST', "workflows/$id/activate" );

    foreach ( array_map( 'intval', $args ) as $order_id ) {
        $order  = wc_get_order( $order_id );
        $status = $order->get_status();
        $before = wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order_id ] ), 'id' );
        $mails  = [];

        yay_automation_check_run(
            $id,
            'order_status_changed',
            [
                'order_id' => $order_id,
                'from'     => 'pending',
                'to'       => $status,
            ]
        );

        $order = wc_get_order( $order_id );
        $notes = [];
        foreach ( wc_get_order_notes( [ 'order_id' => $order_id ] ) as $note ) {
            if ( ! in_array( $note->id, $before, true ) ) {
                $notes[] = $note->content;
            }
        }
        $out[ $order_id ] = [
            'status' => $status . ' → ' . $order->get_status(),
            'meta'   => $order->get_meta( META_KEY ),
            'notes'  => array_reverse( $notes ),
            'mails'  => $mails,
        ];

        // Restore: status, meta, then every note added since (the restore adds one too).
        $order->set_status( $status );
        $order->delete_meta_data( META_KEY );
        $order->save();
        foreach ( wc_get_order_notes( [ 'order_id' => $order_id ] ) as $note ) {
            if ( ! in_array( $note->id, $before, true ) ) {
                wc_delete_order_note( $note->id );
            }
        }
    }//end foreach
} finally {
    // Before shutdown, so the restores above can't start a run. Also covers a failure midway.
    $left = yay_automation_check_remove_workflows( 'Check status flow' );
    if ( $left ) {
        fwrite( STDERR, 'Could not remove workflows: ' . implode( ', ', $left ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
    }
}//end try
echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
