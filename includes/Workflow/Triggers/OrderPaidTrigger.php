<?php
namespace YayMatik\Workflow\Triggers;

defined( 'ABSPATH' ) || exit;

/** Base for events on an order going from an unpaid to a paid status. */
abstract class OrderPaidTrigger extends OrderTrigger {

    /** @var \WC_Order[] Orders in their first save with a paid status, by object id, while that save runs. */
    private $saving_paid = [];

    /** @var true[] Order ids whose first save already had a paid status, in this request. */
    private $paid_when_created = [];

    /**
     * Drops the change from pending that WooCommerce reports for an order saved already paid.
     *
     * @param callable $emit
     */
    public function listen( callable $emit ): void {
        // A new order has no stored status, so its first save reads as a change from pending. Such an order
        // (an import, `wc_create_order()` with a paid status) is caught while it has no id; `woocommerce_new_order`
        // alone can't tell, as it also fires when a draft is placed.
        add_action(
            'woocommerce_before_order_object_save',
            function ( $order ) {
                if ( $order instanceof \WC_Order && ! $order->get_id() && self::is_paid( (string) $order->get_status() ) ) {
                    $this->saving_paid[ spl_object_id( $order ) ] = $order;
                }
            }
        );
        add_action(
            'woocommerce_new_order',
            function ( $order_id, $order = null ) {
                if ( is_object( $order ) && isset( $this->saving_paid[ spl_object_id( $order ) ] ) ) {
                    $this->paid_when_created[ (int) $order_id ] = true;
                    $this->created_paid( $order );
                }
            },
            10,
            2
        );
        add_action(
            'woocommerce_after_order_object_save',
            function ( $order ) {
                unset( $this->saving_paid[ spl_object_id( $order ) ] );
            }
        );
        add_action(
            'woocommerce_order_status_changed',
            function ( $order_id, $from, $to ) use ( $emit ) {
                if ( ! self::is_paid( (string) $from ) && self::is_paid( (string) $to ) && ! isset( $this->paid_when_created[ (int) $order_id ] ) ) {
                    $emit( [ 'order_id' => (int) $order_id ] );
                }
            },
            30,
            3
        );
    }

    /**
     * An order saved already paid, inside its first save.
     *
     * @param \WC_Order $order
     */
    protected function created_paid( \WC_Order $order ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- for triggers that mark it.
    }

    /**
     * Runs at the end of the request: the order must still be paid.
     *
     * @param array $args
     */
    public function claim( array $args ): bool {
        $order = self::order( $args['order_id'] ?? 0 );
        return $order && self::is_paid( (string) $order->get_status() );
    }

    protected static function is_paid( string $status ): bool {
        return in_array( $status, wc_get_is_paid_statuses(), true );
    }
}
