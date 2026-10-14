<?php
namespace YayAutomation\Workflow\Steps\Triggers;

use YayAutomation\Workflow\Data\OrderData;
use YayAutomation\Workflow\Definition\Messages;
use YayAutomation\Workflow\Steps\Core\Field;

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

    public function label(): string {
        return __( 'Order status changed', 'yay-automation' );
    }

    public function description(): string {
        return __( 'Starts when an order moves to another status. Choose which status changes count.', 'yay-automation' );
    }

    public function icon(): string {
        return 'refresh-cw';
    }

    public function fields(): array {
        $statuses = [ OrderData::class, 'statuses' ];
        return [
            Field::multiselect( 'from', __( 'Status changes from', 'yay-automation' ), $statuses )
                ->placeholder( __( 'Any status', 'yay-automation' ) )
                ->help( __( 'Leave empty for any previous status.', 'yay-automation' ) ),
            Field::multiselect( 'to', __( 'Status changes to', 'yay-automation' ), $statuses )
                ->placeholder( __( 'Any status', 'yay-automation' ) )
                ->help( __( 'Leave empty for any new status.', 'yay-automation' ) ),
            Field::toggle( 'only_checkout', __( 'Only run for checkout orders', 'yay-automation' ) )
                ->help( __( 'Skips orders created by admin, REST API, import tools, and other non-checkout sources.', 'yay-automation' ) ),
        ];
    }

    public function summary(): string {
        /* translators: %from% and %to%: order statuses; keep the placeholders as they are. */
        return __( '%from% → %to%', 'yay-automation' );
    }

    /**
     * Statuses are stored without the `wc-` prefix, so it keeps its own sanitize.
     *
     * @param mixed $config Raw config from the request.
     */
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
        foreach ( [ 'from', 'to' ] as $key ) {
            if ( $known && array_diff( $config[ $key ], $known ) ) {
                return $this->invalid( Messages::get( 'unknownStatus' ), $key );
            }
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

    public function refs( array $args ): ?array {
        $refs = parent::refs( $args );
        return $refs ? $refs + [
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
