<?php
namespace YayMatik\Workflow\Triggers;

defined( 'ABSPATH' ) || exit;

/** Fires once per order, the first time it moves from an unpaid to a paid status. */
class OrderPaid extends OrderPaidTrigger {

    public const ID = 'order_paid';

    /** Set on the order once its workflows are queued, so a later unpaid → paid change is ignored. */
    private const META_FIRED = '_yaymatik_order_paid';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Order paid', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Starts once when an order is paid.', 'yaymatik' );
    }

    public function icon(): string {
        return 'circle-check';
    }

    public function fields(): array {
        return [];
    }

    public function summary(): string {
        return __( 'When an order is paid', 'yaymatik' );
    }

    /**
     * Never later either: an order saved already paid counts as started.
     *
     * @param \WC_Order $order
     */
    protected function created_paid( \WC_Order $order ): void {
        $order->update_meta_data( self::META_FIRED, gmdate( 'c' ) );
        $order->save_meta_data();
    }

    /**
     * Still paid, and the first time.
     *
     * @param array $args
     */
    public function claim( array $args ): bool {
        if ( ! parent::claim( $args ) ) {
            return false;
        }
        $order = self::order( $args['order_id'] );
        if ( $order->get_meta( self::META_FIRED ) ) {
            return false;
        }
        $order->update_meta_data( self::META_FIRED, gmdate( 'c' ) );
        $order->save_meta_data();
        return true;
    }
}
