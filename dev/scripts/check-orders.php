<?php
/**
 * Runs one workflow's job on real orders and prints the emails and order notes it made, as JSON.
 * Mail is captured, not sent; the notes it added are deleted again. Run through check-orders.sh.
 *
 * Args: workflow id, trigger id, order ids.
 *
 * @package YayAutomation
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- wp eval-file passes $args.

list( $workflow_id, $trigger_id ) = $args;
$order_ids                        = array_map( 'intval', array_slice( $args, 2 ) );

$mails = [];
add_filter(
    'pre_wp_mail',
    function ( $short_circuit, $atts ) use ( &$mails ) {
        $mails[] = [
            'to'      => $atts['to'],
            'subject' => $atts['subject'],
            'message' => $atts['message'],
        ];
        return true;
    },
    10,
    2
);

$out = [];
foreach ( $order_ids as $order_id ) {
    $mails  = [];
    $before = wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order_id ] ), 'id' );
    YayAutomation\Workflow\Runtime\Runner::get_instance()->run_job( (int) $workflow_id, $trigger_id, [ 'order_id' => $order_id ], [] );
    $notes = [];
    foreach ( wc_get_order_notes( [ 'order_id' => $order_id ] ) as $note ) {
        if ( ! in_array( $note->id, $before, true ) ) {
            $notes[] = [
                'content'       => $note->content,
                'customer_note' => $note->customer_note,
            ];
            wc_delete_order_note( $note->id );
        }
    }
    $out[ $order_id ] = [
        'mails' => $mails,
        'notes' => $notes,
    ];
}
echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
