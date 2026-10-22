<?php
namespace YayMatik\Workflow\Triggers;

use YayMatik\Workflow\DataItems\OrderData;
use YayMatik\Workflow\Core\Steps\Field;

defined( 'ABSPATH' ) || exit;

/** Fires when an order moves to another status; empty `from` / `to` means any. */
class OrderStatusChanged extends OrderTrigger {

    public const ID = 'order_status_changed';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Order status changed', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Starts when an order moves to another status. Choose which status changes count.', 'yaymatik' );
    }

    public function icon(): string {
        return 'shopping-bag';
    }

    public function fields(): array {
        $statuses = [ OrderData::class, 'statuses' ];
        return [
            Field::multiselect( 'from', __( 'Status changes from', 'yaymatik' ), $statuses )
                ->placeholder( __( 'Any status', 'yaymatik' ) )
                ->help( __( 'Leave empty for any previous status.', 'yaymatik' ) ),
            Field::multiselect( 'to', __( 'Status changes to', 'yaymatik' ), $statuses )
                ->placeholder( __( 'Any status', 'yaymatik' ) )
                ->help( __( 'Leave empty for any new status.', 'yaymatik' ) ),
            Field::toggle( 'only_checkout', __( 'Only run for checkout orders', 'yaymatik' ) )
                ->help( __( 'Skips orders created by admin, REST API, import tools, and other non-checkout sources.', 'yaymatik' ) ),
        ];
    }

    public function summary(): string {
        /* translators: %from% and %to%: order statuses; keep the placeholders as they are. */
        return __( '%from% → %to%', 'yaymatik' );
    }

    /**
     * Statuses are stored without the `wc-` prefix, so it keeps its own sanitize.
     *
     * @param mixed $config
     */
    public function sanitize( $config ): array {
        $config = is_array( $config ) ? $config : [];

        return [
            'from'          => self::status_list( $config['from'] ?? [] ),
            'to'            => self::status_list( $config['to'] ?? [] ),
            'only_checkout' => ! empty( $config['only_checkout'] ),
        ];
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
     * @param \WC_Order $order
     */
    private static function from_checkout( \WC_Order $order ): bool {
        return in_array( $order->get_created_via(), [ 'checkout', 'store-api' ], true ) || (bool) $order->get_cart_hash();
    }

    /**
     * @param mixed $raw
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
