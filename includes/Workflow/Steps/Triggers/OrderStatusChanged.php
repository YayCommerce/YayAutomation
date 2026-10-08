<?php
namespace YayAutomation\Workflow\Steps\Triggers;

use YayAutomation\Workflow\Data\OrderData;

defined( 'ABSPATH' ) || exit;

/**
 * Config: [ 'from' => status[], 'to' => status[], 'only_checkout' => bool ]. Empty `from` / `to`
 * means any status. Statuses are slugs without the `wc-` prefix. Event args add `from` and `to`.
 */
class OrderStatusChanged extends OrderTrigger {

    public const ID = 'order_status_changed';

    public function id(): string {
        return self::ID;
    }

    public function sanitize( $config ): array {
        $config = is_array( $config ) ? $config : [];

        return [
            'from'          => self::status_list( $config['from'] ?? [] ),
            'to'            => self::status_list( $config['to'] ?? [] ),
            'only_checkout' => ! empty( $config['only_checkout'] ),
        ];
    }

    public function validate( array $config ) {
        $known = array_keys( OrderData::statuses() );
        // Without WooCommerce there's nothing to check against; the trigger just never fires.
        if ( $known && array_diff( array_merge( $config['from'], $config['to'] ), $known ) ) {
            return $this->invalid( __( 'Unknown order status.', 'yay-automation' ) );
        }
        return true;
    }

    public function listen( callable $emit ): void {
        add_action(
            'woocommerce_order_status_changed',
            function ( $order_id, $from, $to ) use ( $emit ) {
                $emit(
                    [
                        'order_id' => (int) $order_id,
                        'from'     => (string) $from,
                        'to'       => (string) $to,
                    ]
                );
            },
            20,
            3
        );
    }

    public function context( array $args ): ?array {
        $context = parent::context( $args );
        return $context ? $context + [
            'from' => (string) ( $args['from'] ?? '' ),
            'to'   => (string) ( $args['to'] ?? '' ),
        ] : null;
    }

    public function matches( array $context, array $config ): bool {
        $order = $context[ OrderData::ID ];
        return ( ! $config['from'] || in_array( $context['from'], $config['from'], true ) )
            && ( ! $config['to'] || in_array( $context['to'], $config['to'], true ) )
            && ( ! $config['only_checkout'] || self::from_checkout( $order ) );
    }

    /**
     * Placed by a shopper at checkout, not created in the admin, over REST or by an import.
     *
     * @param \WC_Order $order Order.
     */
    private static function from_checkout( \WC_Order $order ): bool {
        return in_array( $order->get_created_via(), [ 'checkout', 'store-api' ], true ) || (bool) $order->get_cart_hash();
    }

    /**
     * Status list from raw input.
     *
     * @param mixed $raw Array of statuses.
     */
    private static function status_list( $raw ): array {
        $list = [];
        foreach ( is_array( $raw ) ? $raw : [] as $status ) {
            $status = is_string( $status ) ? OrderData::status_slug( $status ) : '';
            if ( '' !== $status ) {
                $list[] = $status;
            }
        }
        return array_values( array_unique( $list ) );
    }
}
