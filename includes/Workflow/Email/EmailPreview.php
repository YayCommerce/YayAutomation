<?php
namespace YayMatik\Workflow\Email;

use YayMatik\Workflow\Data\DataRegistry;
use YayMatik\Workflow\Data\OrderData;
use YayMatik\Workflow\Data\SampleValues;
use YayMatik\Workflow\Data\Scope;
use YayMatik\Workflow\Definition\GraphSchema;
use YayMatik\Workflow\Definition\Validator;
use YayMatik\Workflow\Runtime\Executor;
use YayMatik\Workflow\Actions\SendEmail;
use YayMatik\Workflow\Actions\SendInternalEmail;
use YayMatik\Workflow\Steps\Step;
use YayMatik\Workflow\Steps\StepRegistry;
use YayMatik\Workflow\Steps\Trigger;

defined( 'ABSPATH' ) || exit;

/**
 * Preview and Send test for an email step in the editor, from its unsaved config. Only renders:
 * no step runs, so no coupon, order note or meta, no run row and no scheduled job.
 *
 * - Data: the store's latest paid order when the trigger supplies orders (`order` is its number);
 *   otherwise made-up values (`sample` is true, for the "Sample data" badge).
 * - Steps above give made-up outputs (`Step::sample_outputs()`; Generate coupon → YMSAMPLE).
 * - The unsubscribe link goes nowhere (`#`, same look) and there are no List-Unsubscribe headers:
 *   neither may carry a real customer's token. A test email goes to the admin, unsubscribed or not.
 */
final class EmailPreview {

    /** Test emails' subject prefix. */
    public const TEST_PREFIX = '[Test] ';

    /** Paid order statuses a preview reads its data from. */
    private const ORDER_STATUSES = [ 'processing', 'completed' ];

    /**
     * The email as the recipient would see it.
     *
     * @param mixed    $graph    Graph with the step, as the editor has it (unsaved is fine).
     * @param string   $node_id  The email step's node.
     * @param mixed    $config   Its config, as the editor has it.
     * @param int|null $order_id Order to read; null = the latest paid one, 0 = made-up data.
     * @return array|\WP_Error [ 'subject', 'heading', 'html' (wrapped, styles inlined), 'to', 'sample',
     *                         'order' (the order's number; null with made-up data) ].
     */
    public static function render( $graph, string $node_id, $config, ?int $order_id = null ) {
        $email = self::compose( $graph, $node_id, $config, $order_id );
        if ( is_wp_error( $email ) ) {
            return $email;
        }
        return [
            'subject' => $email['subject'],
            'heading' => $email['heading'],
            'html'    => self::inline_styles( $email['body'] ),
            'to'      => $email['to'],
            'sample'  => $email['sample'],
            'order'   => $email['order'],
        ];
    }

    /**
     * Sends the email to an admin, subject prefixed "[Test]".
     *
     * @param mixed    $graph    Graph with the step.
     * @param string   $node_id  The email step's node.
     * @param mixed    $config   Its config.
     * @param string   $to       The admin's address.
     * @param int|null $order_id As in `render()`.
     * @return array|\WP_Error [ 'sentTo', 'subject', 'sample' ].
     */
    public static function send_test( $graph, string $node_id, $config, string $to, ?int $order_id = null ) {
        if ( ! is_email( $to ) ) {
            return new \WP_Error( 'yaymatik_no_email', __( 'Your account has no valid email address.', 'yaymatik' ), [ 'status' => 400 ] );
        }
        $email = self::compose( $graph, $node_id, $config, $order_id );
        if ( is_wp_error( $email ) ) {
            return $email;
        }
        $subject = self::TEST_PREFIX . $email['subject'];
        if ( ! WC()->mailer()->send( $to, $subject, $email['body'], $email['headers'] ) ) {
            return new \WP_Error( 'yaymatik_mail_failed', __( 'Couldn’t send the test email.', 'yaymatik' ), [ 'status' => 500 ] );
        }
        return [
            'sentTo'  => $to,
            'subject' => $subject,
            'sample'  => $email['sample'],
        ];
    }

    /**
     * @param mixed    $graph    Graph.
     * @param string   $node_id  Node.
     * @param mixed    $config   Raw config.
     * @param int|null $order_id Order.
     * @return array|\WP_Error Compose output + `to`, `sample`, `order`.
     */
    private static function compose( $graph, string $node_id, $config, ?int $order_id ) {
        $graph = GraphSchema::prepare( is_array( $graph ) ? self::with_config( $graph, $node_id, $config ) : $graph );
        if ( is_wp_error( $graph ) ) {
            return $graph;
        }
        $nodes = array_column( $graph['nodes'], null, 'id' );
        $node  = $nodes[ $node_id ] ?? null;
        $step  = $node && $node['data']['block'] ? StepRegistry::get( $node['data']['block'] ) : null;
        if ( ! $step instanceof SendEmail && ! $step instanceof SendInternalEmail ) {
            return new \WP_Error( 'yaymatik_not_email', __( 'Only email steps can be previewed.', 'yaymatik' ), [ 'status' => 400 ] );
        }

        // The same checks as Activate, for this step only: settings, then the variables it uses.
        $issues = array_values(
            array_filter(
                Validator::errors(
                    [
                        'name'  => 'Preview',
                        'graph' => $graph,
                    ]
                ),
                fn( array $issue ) => ( $issue['nodeId'] ?? null ) === $node_id
            )
        );
        if ( $issues ) {
            return new \WP_Error(
                'yaymatik_invalid_step',
                $issues[0]['message'],
                [
                    'status' => 400,
                    'errors' => $issues,
                ]
            );
        }

        $config                          = $step->sanitize( $node['data']['config'] );
        list( $values, $sample, $order ) = self::values( $graph, $nodes, $node_id, $order_id );
        if ( $step instanceof SendEmail ) {
            $email       = $step->compose( $config, $values, null );
            $email['to'] = SendEmail::recipient( $config, $values );
        } else {
            $email       = $step->compose( $config, $values ) + [ 'headers' => "Content-Type: text/html\r\n" ];
            $email['to'] = (string) $config['recipients'];
        }
        $email['sample'] = $sample;
        $email['order']  = $order;
        return $email;
    }

    /**
     * The values a preview reads, and whether they're made up.
     *
     * @param array    $graph    Prepared graph.
     * @param array[]  $nodes    Nodes by id.
     * @param string   $node_id  The email step.
     * @param int|null $order_id Order to read; null = latest paid; 0 = made-up.
     * @return array [ Values, bool $sample, string|null $order_number ].
     */
    private static function values( array $graph, array $nodes, string $node_id, ?int $order_id ): array {
        $trigger = null;
        $outputs = [];
        foreach ( Validator::steps_above( $graph, $node_id ) as $id ) {
            $step = $nodes[ $id ]['data']['block'] ? StepRegistry::get( $nodes[ $id ]['data']['block'] ) : null;
            if ( $step instanceof Trigger ) {
                $trigger = $step;
            } elseif ( $step instanceof Step ) {
                $outputs[ $id ] = $step->sample_outputs( $step->sanitize( $nodes[ $id ]['data']['config'] ) );
            }
        }

        $provides = $trigger ? $trigger->provides() : [];
        $refs     = null;
        if ( in_array( OrderData::ID, $provides, true ) ) {
            $order_id = $order_id ?? self::latest_paid_order();
            $refs     = $order_id ? $trigger->refs( [ 'order_id' => $order_id ] ) : null;
        }

        $scope = new Scope(
            [
                'trigger' => $refs ?? [],
                'steps'   => $outputs,
            ]
        );
        $scope->set_output_display( Executor::output_display( $nodes, [ StepRegistry::class, 'get' ] ) );
        if ( $refs ) {
            $order = wc_get_order( $order_id );
            return [ $scope, false, $order ? (string) $order->get_order_number() : null ];
        }
        return [ new SampleValues( $scope, array_intersect_key( DataRegistry::items(), array_flip( $provides ) ) ), true, null ];
    }

    /** The newest processing or completed order's id; 0 when there's none. */
    private static function latest_paid_order(): int {
        $ids = wc_get_orders(
            [
                'type'    => 'shop_order',
                'status'  => self::ORDER_STATUSES,
                'limit'   => 1,
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'ids',
            ]
        );
        return is_array( $ids ) && $ids ? (int) $ids[0] : 0;
    }

    /**
     * The graph with the node's config replaced by the editor's.
     *
     * @param array  $graph   Raw graph.
     * @param string $node_id Node.
     * @param mixed  $config  Raw config.
     */
    private static function with_config( array $graph, string $node_id, $config ): array {
        foreach ( is_array( $graph['nodes'] ?? null ) ? $graph['nodes'] : [] as $i => $node ) {
            if ( is_array( $node ) && ( $node['id'] ?? null ) === $node_id ) {
                $graph['nodes'][ $i ]['data']['config'] = is_array( $config ) ? $config : [];
            }
        }
        return $graph;
    }

    /**
     * WooCommerce's email styles inlined, as the mailer does when it sends.
     *
     * @param string $html Wrapped email.
     */
    private static function inline_styles( string $html ): string {
        return class_exists( 'WC_Email' ) ? ( new \WC_Email() )->style_inline( $html ) : $html;
    }
}
