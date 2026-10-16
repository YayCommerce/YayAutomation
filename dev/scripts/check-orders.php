<?php
/**
 * Runs a temporary high-value-style workflow on two temporary orders, through real WooCommerce
 * events, the Dispatcher and the Worker, and prints the emails, order notes and order meta it made,
 * as JSON keyed `high` / `low`; order numbers read `{order}` and mail bodies are text, so two runs
 * diff cleanly. Each order has one line item of a temporary product. Mail is really sent (local
 * Mailpit) and captured too. Removes the workflow, the orders and the product (force delete), their
 * runs and jobs, then prints what is left to STDERR: every count must be 0. Run through
 * check-orders.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- wp eval-file runs in global scope.

require_once __DIR__ . '/check-run.php';

use YayMatik\Workflow\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Runtime\Runtime;
use YayMatik\Workflow\Storage\RunStore;
use YayMatik\Workflow\Storage\WorkflowRepository;

const CHECK_ORDERS_PREFIX = 'Check orders:';
const CHECK_ORDERS_META   = '_yaymatik_check_orders';
const CHECK_ORDERS_STAFF  = 'staff-check@example.test';

global $wpdb;
wp_set_current_user( 1 );
if ( ! Runtime::worker() ) {
    WP_CLI::error( 'Runtime not booted (WooCommerce off?).' );
}

$mails = [];
add_filter(
    'wp_mail',
    function ( $atts ) use ( &$mails ) {
        // Text only: the store's email wrapper would bury the workflow's content in the diff.
        $text    = html_entity_decode( wp_strip_all_tags( (string) $atts['message'] ), ENT_QUOTES, 'UTF-8' );
        $mails[] = [
            'to'      => implode( ', ', (array) $atts['to'] ),
            'subject' => $atts['subject'],
            'message' => implode( "\n", array_filter( array_map( 'trim', explode( "\n", $text ) ) ) ),
        ];
        return $atts;
    }
);

$node = fn( string $id, string $kind, int $y, string $block, array $config ) => [
    'id'       => $id,
    'kind'     => $kind,
    'position' => [
        'x' => 0,
        'y' => $y,
    ],
    'data'     => [
        'block'  => $block,
        'config' => (object) $config,
    ],
];
$edge = fn( string $source, string $target, ?string $handle = null ) => array_filter(
    [
        'id'           => "e-{$source}-{$target}",
        'source'       => $source,
        'target'       => $target,
        'sourceHandle' => $handle,
    ]
);

// The high-value template's shape: order created → total ≥ 500 → email + meta, otherwise a note.
$graph = [
    'nodes' => [
        $node( 'trigger', 'trigger', 0, 'order_created', [] ),
        $node(
            'condition',
            'condition',
            170,
            'condition',
            [
                'match' => 'all',
                'rules' => [
                    [
                        'field'    => 'order.total',
                        'operator' => 'greater_or_equal',
                        'value'    => '500',
                    ],
                ],
            ]
        ),
        $node(
            'email',
            'action',
            340,
            'send_internal_email',
            [
                'recipients' => CHECK_ORDERS_STAFF,
                'subject'    => 'High-value order {{ order.number }} - {{ order.total }}',
                'message'    => "Order: {{ order.number }}\nTotal: {{ order.total }}\nCustomer: {{ customer.full_name }} ({{ customer.email }})\n\nItems:\n{{ order.items }}",
            ]
        ),
        $node(
            'meta',
            'action',
            510,
            'update_order_meta',
            [
                'key'   => CHECK_ORDERS_META,
                'value' => 'High-value order {{ order.number }} for {{ customer.email }}',
            ]
        ),
        $node( 'note', 'action', 340, 'add_order_note', [ 'note' => 'Check-orders: order {{ order.number }} is under 500 ({{ order.total }})' ] ),
    ],
    'edges' => [
        $edge( 'trigger', 'condition' ),
        $edge( 'condition', 'email', 'then' ),
        $edge( 'email', 'meta' ),
        $edge( 'condition', 'note', 'otherwise' ),
    ],
];

$call = function ( string $method, string $route, ?array $body = null ) {
    $request = new WP_REST_Request( $method, '/yaymatik/v1/' . $route );
    if ( null !== $body ) {
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( wp_json_encode( $body ) );
    }
    return rest_do_request( $request )->get_data();
};

// Leftovers of an earlier, interrupted run would match this run's events too.
yaymatik_check_remove_workflows( CHECK_ORDERS_PREFIX );

$since       = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . RunStore::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$workflow_id = 0;
$product_id  = 0;
$orders      = [];
$out         = [];
$error       = '';

try {
    $workflow    = $call(
        'POST',
        'workflows',
        [
            'name'  => CHECK_ORDERS_PREFIX . ' high-value (temporary)',
            'graph' => $graph,
        ]
    );
    $workflow_id = (int) ( $workflow['id'] ?? 0 );
    if ( ! $workflow_id ) {
        throw new RuntimeException( 'Could not create the workflow: ' . wp_json_encode( $workflow ) );
    }
    $call( 'POST', "workflows/{$workflow_id}/activate" );

    // One line item per order, priced at the order's total, so the email's items table is filled.
    $product = new WC_Product_Simple();
    $product->set_name( 'Check orders product (temporary)' );
    $product->set_status( 'private' );
    $product->set_regular_price( '1' );
    $product_id = $product->save();

    foreach ( [
        'high' => 600,
        'low'  => 100,
    ] as $label => $total ) {
        $mails = [];
        $order = wc_create_order( [ 'status' => 'pending' ] );
        $order->set_billing_first_name( 'Zed' );
        $order->set_billing_last_name( 'Checkorder' );
        $order->set_billing_email( 'zed.checkorder@example.test' );
        $order->add_product(
            $product,
            1,
            [
                'subtotal' => $total,
                'total'    => $total,
            ]
        );
        $order->calculate_totals( false );
        $orders[ $label ] = $order->get_id();

        // The order's own event, as at shutdown; only this workflow's run is taken right away.
        Runtime::dispatcher()->flush();
        $run_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RunStore::table() . ' WHERE id > %d AND workflow_id = %d AND subject = %s', $since, $workflow_id, 'order:' . $order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $run_id ) {
            as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $run_id ], ActionSchedulerQueue::GROUP );
            Runtime::worker()->run( $run_id );
        }
        $run   = $run_id ? RunStore::find( $run_id ) : null;
        $order = wc_get_order( $order->get_id() );
        $plain = fn( $text ) => str_replace( $order->get_order_number(), '{order}', (string) $text );

        $out[ $label ] = [
            'run'   => $run ? $run['status'] . ( $run['error'] ? ': ' . $run['error'] : '' ) : 'no run',
            'mails' => array_map( fn( $m ) => array_map( $plain, $m ), $mails ),
            'notes' => array_map( $plain, wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' ) ),
            'meta'  => $plain( $order->get_meta( CHECK_ORDERS_META ) ),
        ];
    }//end foreach
} catch ( Throwable $e ) {
    $error = $e->getMessage();
} finally {
    yaymatik_check_remove_workflows( CHECK_ORDERS_PREFIX );
    foreach ( $orders as $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->delete( true );
        }
    }
    $product = $product_id ? wc_get_product( $product_id ) : null;
    if ( $product ) {
        $product->delete( true );
    }
    $left = yaymatik_check_remove_runs( $since, [ $workflow_id ], array_values( $orders ) );
}//end try

echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";

$left = [
    'workflow' => $workflow_id && WorkflowRepository::find( $workflow_id ) ? 1 : 0,
    'orders'   => count( array_filter( array_map( 'wc_get_order', $orders ) ) ),
    'product'  => $product_id && wc_get_product( $product_id ) ? 1 : 0,
    'runs'     => $left['runs'],
    'jobs'     => $left['jobs'],
];
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
fwrite( STDERR, 'Left: ' . implode( ', ', array_map( fn( $k, $v ) => "{$k} {$v}", array_keys( $left ), $left ) ) . "\n" );
if ( $error ) {
    WP_CLI::error( $error );
}
if ( array_sum( $left ) ) {
    WP_CLI::halt( 1 );
}
