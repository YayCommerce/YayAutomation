<?php
namespace YayMatik\Workflow\Core\Email;

use YayMatik\Workflow\Core\Cart\SavedCart;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Storage\CartStore;
use YayMatik\Workflow\DataItems\CartData;
use YayMatik\Workflow\DataItems\CustomerData;
use YayMatik\Workflow\DataItems\OrderData;
use YayMatik\Workflow\Core\Data\SampleValues;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Definition\GraphSchema;
use YayMatik\Workflow\Core\Definition\Validator;
use YayMatik\Workflow\Core\Runtime\Executor;
use YayMatik\Workflow\Core\Steps\Step;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Core\Steps\Trigger;
use YayMatik\Workflow\Extension\StepIndex;

defined( 'ABSPATH' ) || exit;

/**
 * Preview and Send test for an email step, from its unsaved config; renders only, no step runs.
 */
final class EmailPreview {

    public const TEST_PREFIX = '[Test] ';

    private const ORDER_STATUSES = [ 'processing', 'completed' ];

    /** Paid orders looked at for one whose suggested-products grid isn't empty. */
    private const GRID_SCAN = 20;

    /** @var array[] Kind => `tag` (a merge tag), `has` (fn( \WC_Order ): bool): a preview picks an order that has something for the tags it uses. */
    private static $sample_needs = [];

    /**
     * The email as the recipient would see it.
     *
     * @param mixed    $graph
     * @param string   $node_id
     * @param mixed    $config
     * @param int|null $order_id Null = the latest paid order (a cart trigger: the newest cart; a customer-only
     *                           trigger: the newest customer account), 0 = made-up data.
     * @return array|\WP_Error [ 'subject', 'heading', 'html' (as sent), 'to', 'sample', 'order' (number or null),
     *                         'customer' (account name or null), 'sampleProducts' (grid kinds filled with newest products) ]
     */
    public static function render( $graph, string $node_id, $config, ?int $order_id = null ) {
        $email = self::compose( $graph, $node_id, $config, $order_id, false );
        if ( is_wp_error( $email ) ) {
            return $email;
        }
        return [
            'subject'        => $email['subject'],
            'heading'        => $email['heading'],
            'html'           => $email['raw'] ? $email['body'] : self::inline_styles( $email['body'] ),
            'to'             => $email['to'],
            'sample'         => $email['sample'],
            'order'          => $email['order'],
            'customer'       => $email['customer'],
            'sampleProducts' => $email['sample_products'],
        ];
    }

    /**
     * Sends the email to an admin, unsubscribed or not.
     *
     * @param mixed    $graph
     * @param string   $node_id
     * @param mixed    $config
     * @param string   $to
     * @param int|null $order_id As in `render()`.
     * @return array|\WP_Error [ 'sentTo', 'subject', 'sample', 'sampleProducts' ]
     */
    public static function send_test( $graph, string $node_id, $config, string $to, ?int $order_id = null ) {
        if ( ! is_email( $to ) ) {
            return new \WP_Error( 'yaymatik_no_email', __( 'Your account has no valid email address.', 'yaymatik-automation-for-woocommerce' ), [ 'status' => 400 ] );
        }
        $email = self::compose( $graph, $node_id, $config, $order_id, true );
        if ( is_wp_error( $email ) ) {
            return $email;
        }
        $subject = self::TEST_PREFIX . $email['subject'];
        if ( ! EmailDelivery::send( $to, [ 'subject' => $subject ] + $email ) ) {
            return new \WP_Error( 'yaymatik_mail_failed', __( 'Couldn’t send the test email.', 'yaymatik-automation-for-woocommerce' ), [ 'status' => 500 ] );
        }
        return [
            'sentTo'         => $to,
            'subject'        => $subject,
            'sample'         => $email['sample'],
            'sampleProducts' => $email['sample_products'],
        ];
    }

    /**
     * @param mixed    $graph
     * @param string   $node_id
     * @param mixed    $config
     * @param int|null $order_id
     * @param bool     $test     Send test.
     * @return array|\WP_Error Compose output + `to`, `sample`, `order`, `customer`, `sample_products`.
     */
    private static function compose( $graph, string $node_id, $config, ?int $order_id, bool $test ) {
        $graph = is_array( $graph ) ? self::with_config( $graph, $node_id, $config ) : $graph;
        // A step of a plugin that's off stays in the graph, as in the saved workflow.
        $graph = GraphSchema::prepare( $graph, is_array( $graph['nodes'] ?? null ) ? array_filter( $graph['nodes'], fn( $node ) => is_array( $node ) && null !== StepIndex::inactive( (string) ( $node['data']['block'] ?? '' ) ) ) : [] );
        if ( is_wp_error( $graph ) ) {
            return $graph;
        }
        $nodes = GraphSchema::by_id( $graph['nodes'] );
        $node  = $nodes[ $node_id ] ?? null;
        $step  = $node && $node['data']['block'] ? StepRegistry::get( $node['data']['block'] ) : null;
        if ( ! $step instanceof EmailStep ) {
            return new \WP_Error( 'yaymatik_not_email', __( 'Only email steps can be previewed.', 'yaymatik-automation-for-woocommerce' ), [ 'status' => 400 ] );
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

        $config = $step->sanitize( $node['data']['config'] );
        $kinds  = self::grid_kinds( $config );
        list( $values, $sample, $order, $missing, $customer ) = self::values( $graph, $nodes, $node_id, $order_id, $kinds );
        // No unsubscribe link (`#`) or List-Unsubscribe header: neither may carry a real customer's token.
        $build = function () use ( $step, $config, $values, $test ) {
            $email       = $step->compose_email( $config, $values, null, $test );
            $email['to'] = $step->email_recipient( $config, $values );
            return $email;
        };
        try {
            $email = $missing ? ProductGrid::with_newest_when_empty( $build ) : $build();
        } catch ( \RuntimeException $e ) {
            // Another plugin's `build` failed; the reason is in Health check › Extensions.
            return new \WP_Error( 'yaymatik_email_build', htmlspecialchars_decode( $e->getMessage(), ENT_QUOTES ), [ 'status' => 400 ] );
        }
        $email['sample']          = $sample;
        $email['order']           = $order;
        $email['customer']        = $customer;
        $email['sample_products'] = $missing;
        return $email;
    }

    /**
     * The values a preview reads, and whether they're made up.
     *
     * @param array    $graph
     * @param array[]  $nodes   By id.
     * @param string   $node_id
     * @param int|null $order_id
     * @param string[] $kinds
     * @return array [ Values, bool $sample, string|null $order_number, string[] $missing grid kinds, string|null $customer name ]
     */
    private static function values( array $graph, array $nodes, string $node_id, ?int $order_id, array $kinds ): array {
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

        $provides = $trigger ? $trigger->resolved_provides() : [];
        $refs     = null;
        $resolver = null;
        $missing  = [];
        $customer = null;
        if ( in_array( OrderData::ID, $provides, true ) ) {
            if ( null === $order_id && $kinds ) {
                // Each order loaded once: what it has decides both the pick and what the preview fills in.
                $present = [];
                foreach ( self::paid_orders( self::GRID_SCAN ) as $id ) {
                    $present[ $id ] = self::grids_of( wc_get_order( $id ), $kinds );
                }
                $order_id = self::pick_order( $present );
                $missing  = $order_id ? array_values( array_diff( $kinds, $present[ $order_id ] ) ) : [];
            } else {
                $order_id = $order_id ?? ( self::paid_orders( 1 )[0] ?? 0 );
                $missing  = $order_id && $kinds ? array_values( array_diff( $kinds, self::grids_of( wc_get_order( $order_id ), $kinds ) ) ) : [];
            }
            $refs = $order_id ? $trigger->resolved_refs( [ 'order_id' => $order_id ] ) : null;
        } elseif ( in_array( CartData::ID, $provides, true ) && 0 !== $order_id ) {
            $cart_id = CartStore::newest_for_preview();
            $refs    = $cart_id ? $trigger->resolved_refs( [ 'cart_id' => $cart_id ] ) : null;
            // A real cart, but its restore link must not reach a test inbox.
            $resolver = new RefResolver(
                [
                    CartData::ID => function ( string $id ) {
                        $cart = SavedCart::find( (int) $id );
                        return $cart ? $cart->inert() : null;
                    },
                ] + RefResolver::default_loaders()
            );
        } elseif ( [ CustomerData::ID ] === array_values( array_diff( $provides, DataRegistry::ALWAYS ) ) && 0 !== $order_id ) {
            $user     = self::newest_customer();
            $refs     = $user ? [ CustomerData::ID => new Ref( CustomerData::ID, 'user:' . $user->ID ) ] : null;
            $customer = $user ? $user->display_name : null;
        }

        $scope = new Scope(
            [
                // Facts are only known at event time: previews read the trigger's sample values.
                'trigger' => ( $refs ?? [] ) + ( $trigger ? $trigger->sample_facts() : [] ),
                'steps'   => $outputs,
            ],
            $resolver
        );
        $scope->set_output_display( Executor::output_display( $nodes, [ StepRegistry::class, 'get' ] ) );
        if ( $refs ) {
            $order = $order_id ? wc_get_order( $order_id ) : null;
            return [ $scope, false, $order ? (string) $order->get_order_number() : null, $missing, $customer ];
        }
        return [ new SampleValues( $scope, array_intersect_key( DataRegistry::items(), array_flip( $provides ) ) ), true, null, [], null ];
    }

    /**
     * The newest customer account, for a trigger whose only data is the customer.
     *
     * @return \WP_User|null
     */
    private static function newest_customer() {
        $users = get_users(
            [
                'role'    => 'customer',
                // Accounts made in the same second tie on `registered`: the higher id is the newer one.
                'orderby' => [
                    'registered' => 'DESC',
                    'ID'         => 'DESC',
                ],
                'number'  => 1,
            ]
        );
        return $users ? $users[0] : null;
    }

    /**
     * The order and account a plugin's editor previews with, as Preview picks them (product grids aside); null when made up.
     *
     * @param mixed $graph
     * @return array [ 'orderId' => int|null, 'userId' => int|null ]
     */
    public static function sample_ids( $graph ): array {
        $node     = is_array( $graph ) && is_array( $graph['nodes'] ?? null ) ? GraphSchema::trigger( $graph['nodes'] ) : null;
        $trigger  = is_array( $node ) && is_string( $node['data']['block'] ?? null ) ? StepRegistry::get( $node['data']['block'] ) : null;
        $provides = $trigger instanceof Trigger ? $trigger->resolved_provides() : [];
        if ( in_array( OrderData::ID, $provides, true ) ) {
            $order = wc_get_order( self::paid_orders( 1 )[0] ?? 0 );
            return [
                'orderId' => $order ? (int) $order->get_id() : null,
                'userId'  => $order && $order->get_customer_id() ? (int) $order->get_customer_id() : null,
            ];
        }
        if ( in_array( CartData::ID, $provides, true ) ) {
            $cart = SavedCart::find( (int) CartStore::newest_for_preview() );
            return [
                'orderId' => null,
                'userId'  => $cart && $cart->user_id() ? $cart->user_id() : null,
            ];
        }
        $user = [ CustomerData::ID ] === array_values( array_diff( $provides, DataRegistry::ALWAYS ) ) ? self::newest_customer() : null;
        return [
            'orderId' => null,
            'userId'  => $user ? (int) $user->ID : null,
        ];
    }

    /**
     * A merge tag whose preview wants an order that has something for it; a preview without one fills the tag's grid with the newest products.
     *
     * @internal
     * @param string   $kind The name `sampleProducts` gives the client.
     * @param string   $tag  A merge tag, without braces.
     * @param callable $has  fn( \WC_Order $order ): bool.
     */
    public static function add_sample_need( string $kind, string $tag, callable $has ): void {
        self::$sample_needs[ $kind ] = [
            'tag' => $tag,
            'has' => $has,
        ];
    }

    /**
     * The grid kinds an email's config shows, by merge tag.
     *
     * @param array $config
     * @return string[]
     */
    public static function grid_kinds( array $config ): array {
        $text = (string) wp_json_encode( $config );
        return array_keys( array_filter( self::$sample_needs, fn( array $need ) => false !== strpos( $text, $need['tag'] ) ) );
    }

    /**
     * The order with the most of the email's grids, the newest among equals; 0 for none.
     *
     * @param array $present Order id => grid kinds it has, newest first.
     */
    public static function pick_order( array $present ): int {
        $best = 0;
        $most = -1;
        foreach ( $present as $id => $kinds ) {
            if ( count( $kinds ) > $most ) {
                $best = (int) $id;
                $most = count( $kinds );
            }
        }
        return $best;
    }

    /**
     * The grid kinds this order has something for.
     *
     * @param \WC_Order|false $order
     * @param string[]        $kinds
     * @return string[]
     */
    private static function grids_of( $order, array $kinds ): array {
        if ( ! $order instanceof \WC_Order ) {
            return [];
        }
        return array_values( array_filter( $kinds, fn( string $kind ) => (bool) ( self::$sample_needs[ $kind ]['has'] )( $order ) ) );
    }

    /**
     * The newest processing or completed orders' ids.
     *
     * @param int $limit
     * @return int[]
     */
    private static function paid_orders( int $limit ): array {
        $ids = wc_get_orders(
            [
                'type'    => 'shop_order',
                'status'  => self::ORDER_STATUSES,
                'limit'   => $limit,
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'ids',
            ]
        );
        return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
    }

    /**
     * The graph with the node's config replaced by the editor's.
     *
     * @param array  $graph
     * @param string $node_id
     * @param mixed  $config
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
     * @param string $html
     */
    private static function inline_styles( string $html ): string {
        return class_exists( 'WC_Email' ) ? ( new \WC_Email() )->style_inline( $html ) : $html;
    }
}
