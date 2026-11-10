<?php
namespace YayMatik\Workflow\Core\Email;

use YayMatik\Workflow\Core\Cart\SavedCart;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Storage\CartStore;
use YayMatik\Workflow\DataItems\CartData;
use YayMatik\Workflow\DataItems\OrderData;
use YayMatik\Workflow\Core\Data\SampleValues;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Definition\GraphSchema;
use YayMatik\Workflow\Core\Definition\Validator;
use YayMatik\Workflow\Core\Runtime\Executor;
use YayMatik\Workflow\Actions\SendEmail;
use YayMatik\Workflow\Actions\SendInternalEmail;
use YayMatik\Workflow\Core\Steps\Step;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Core\Steps\Trigger;

defined( 'ABSPATH' ) || exit;

/**
 * Preview and Send test for an email step, from its unsaved config; renders only, no step runs.
 */
final class EmailPreview {

    public const TEST_PREFIX = '[Test] ';

    private const ORDER_STATUSES = [ 'processing', 'completed' ];

    /** Paid orders looked at for one whose suggested-products grid isn't empty. */
    private const GRID_SCAN = 20;

    /** Merge tags of the order grids => their `ProductGrid` kind. */
    private const GRID_TAGS = [
        'order.cross_sell_products' => ProductGrid::CROSS_SELLS,
        'order.upsell_products'     => ProductGrid::UPSELLS,
    ];

    /**
     * The email as the recipient would see it.
     *
     * @param mixed    $graph
     * @param string   $node_id
     * @param mixed    $config
     * @param int|null $order_id Null = the latest paid order (a cart trigger: the newest cart), 0 = made-up data.
     * @return array|\WP_Error [ 'subject', 'heading', 'html' (wrapped, styles inlined), 'to', 'sample',
     *                         'order' (the order's number; null with made-up data), 'sampleProducts' (the grid kind
     *                         shown with the store's newest products, as no recent order has any; or null) ]
     */
    public static function render( $graph, string $node_id, $config, ?int $order_id = null ) {
        $email = self::compose( $graph, $node_id, $config, $order_id );
        if ( is_wp_error( $email ) ) {
            return $email;
        }
        return [
            'subject'        => $email['subject'],
            'heading'        => $email['heading'],
            'html'           => self::inline_styles( $email['body'] ),
            'to'             => $email['to'],
            'sample'         => $email['sample'],
            'order'          => $email['order'],
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
     * @return array|\WP_Error Compose output + `to`, `sample`, `order`, `sample_products`.
     */
    private static function compose( $graph, string $node_id, $config, ?int $order_id ) {
        $graph = GraphSchema::prepare( is_array( $graph ) ? self::with_config( $graph, $node_id, $config ) : $graph );
        if ( is_wp_error( $graph ) ) {
            return $graph;
        }
        $nodes = GraphSchema::by_id( $graph['nodes'] );
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

        $config                                    = $step->sanitize( $node['data']['config'] );
        $kinds                                     = self::grid_kinds( $config );
        list( $values, $sample, $order, $missing ) = self::values( $graph, $nodes, $node_id, $order_id, $kinds );
        $build                                     = function () use ( $step, $config, $values ) {
            if ( $step instanceof SendEmail ) {
                // No unsubscribe link (`#`) or List-Unsubscribe header: neither may carry a real customer's token.
                $email       = $step->compose( $config, $values, null );
                $email['to'] = SendEmail::recipient( $config, $values );
                return $email;
            }
            $email       = $step->compose( $config, $values ) + [ 'headers' => "Content-Type: text/html\r\n" ];
            $email['to'] = (string) $config['recipients'];
            return $email;
        };
        $email                                     = $missing ? ProductGrid::with_newest_when_empty( $build ) : $build();
        $email['sample']                           = $sample;
        $email['order']                            = $order;
        $email['sample_products']                  = $missing;
        return $email;
    }

    /**
     * The values a preview reads, and whether they're made up.
     *
     * @param array    $graph
     * @param array[]  $nodes   By id.
     * @param string   $node_id
     * @param int|null $order_id
     * @param string[] $kinds   Grid kinds the email shows.
     * @return array [ Values, bool $sample, string|null $order_number, string|null $missing ] `$missing`: the first
     *               grid kind the order has nothing for, shown with the store's newest products.
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
        if ( in_array( OrderData::ID, $provides, true ) ) {
            $order_id = $order_id ?? ( $kinds ? self::order_with_grids( $kinds ) : self::latest_paid_order() );
            $refs     = $order_id ? $trigger->resolved_refs( [ 'order_id' => $order_id ] ) : null;
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
        }

        $scope = new Scope(
            [
                'trigger' => $refs ?? [],
                'steps'   => $outputs,
            ],
            $resolver
        );
        $scope->set_output_display( Executor::output_display( $nodes, [ StepRegistry::class, 'get' ] ) );
        if ( $refs ) {
            $order   = $order_id ? wc_get_order( $order_id ) : null;
            $missing = null;
            foreach ( $order ? $kinds : [] as $kind ) {
                if ( ! ProductGrid::for_order( $order, $kind ) ) {
                    $missing = $kind;
                    break;
                }
            }
            return [ $scope, false, $order ? (string) $order->get_order_number() : null, $missing ];
        }
        return [ new SampleValues( $scope, array_intersect_key( DataRegistry::items(), array_flip( $provides ) ) ), true, null, null ];
    }

    /**
     * The grid kinds an email's config shows, by merge tag.
     *
     * @param array $config
     * @return string[]
     */
    public static function grid_kinds( array $config ): array {
        $text = (string) wp_json_encode( $config );
        return array_values( array_filter( self::GRID_TAGS, fn( string $tag ) => false !== strpos( $text, $tag ), ARRAY_FILTER_USE_KEY ) );
    }

    /**
     * The first of these orders, newest first, that has something for every kind; 0 when none has.
     *
     * @param int[]    $ids
     * @param string[] $kinds
     * @param callable $has_grid fn( int $order_id, string $kind ): bool.
     */
    public static function pick_order( array $ids, array $kinds, callable $has_grid ): int {
        foreach ( $ids as $id ) {
            if ( ! array_filter( $kinds, fn( string $kind ) => ! $has_grid( (int) $id, $kind ) ) ) {
                return (int) $id;
            }
        }
        return 0;
    }

    /**
     * The newest paid order with something in these grids, among the last GRID_SCAN; else the newest paid order.
     *
     * @param string[] $kinds
     */
    private static function order_with_grids( array $kinds ): int {
        $ids    = wc_get_orders(
            [
                'type'    => 'shop_order',
                'status'  => self::ORDER_STATUSES,
                'limit'   => self::GRID_SCAN,
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'ids',
            ]
        );
        $ids    = is_array( $ids ) ? array_map( 'intval', $ids ) : [];
        $picked = self::pick_order(
            $ids,
            $kinds,
            function ( int $id, string $kind ): bool {
                $order = wc_get_order( $id );
                return $order instanceof \WC_Order && (bool) ProductGrid::for_order( $order, $kind );
            }
        );
        return $picked ? $picked : ( $ids[0] ?? 0 );
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
