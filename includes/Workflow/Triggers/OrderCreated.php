<?php
namespace YayMatik\Workflow\Triggers;

defined( 'ABSPATH' ) || exit;

/** Fires once per order when it's placed, before payment is confirmed; drafts count once they leave draft. */
class OrderCreated extends OrderTrigger {

    public const ID = 'order_created';

    /** Set on the order once its workflows are queued, so later hooks for the same order are ignored. */
    private const META_FIRED = '_yaymatik_order_created';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Order created', 'yaymatik-automation-for-woocommerce' );
    }

    public function description(): string {
        return __( 'Starts when a new order is created.', 'yaymatik-automation-for-woocommerce' );
    }

    public function icon(): string {
        return 'shopping-bag';
    }

    public function fields(): array {
        return [];
    }

    public function summary(): string {
        return __( 'When a new order is created', 'yaymatik-automation-for-woocommerce' );
    }

    public function listen( callable $emit ): void {
        add_action(
            'woocommerce_new_order',
            function ( $order_id, $order = null ) use ( $emit ) {
                $status = $order instanceof \WC_Order ? $order->get_status() : '';
                if ( ! self::is_draft( $status ) ) {
                    $emit( [ 'order_id' => (int) $order_id ] );
                }
            },
            100,
            2
        );
        // Block checkout creates a `checkout-draft` order early and places it by changing the status.
        add_action(
            'woocommerce_order_status_changed',
            function ( $order_id, $from, $to ) use ( $emit ) {
                if ( self::is_draft( (string) $from ) && ! self::is_draft( (string) $to ) ) {
                    $emit( [ 'order_id' => (int) $order_id ] );
                }
            },
            50,
            3
        );
    }

    /**
     * Runs at the end of the request, once the order is fully saved.
     *
     * @param array $args
     */
    public function claim( array $args ): bool {
        $order = self::order( $args['order_id'] ?? 0 );
        if ( ! $order || self::is_draft( $order->get_status() ) || $order->get_meta( self::META_FIRED ) ) {
            return false;
        }
        $order->update_meta_data( self::META_FIRED, gmdate( 'c' ) );
        $order->save_meta_data();
        return true;
    }
}
