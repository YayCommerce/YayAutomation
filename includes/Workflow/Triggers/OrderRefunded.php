<?php
namespace YayMatik\Workflow\Triggers;

use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\DataItems\RefundData;

defined( 'ABSPATH' ) || exit;

/** Fires once per refund, partial or full; the run is about the order. */
class OrderRefunded extends OrderTrigger {

    public const ID = 'order_refunded';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Order refunded', 'yaymatik-automation-for-woocommerce' );
    }

    public function description(): string {
        return __( 'Starts for each refund, partial or full.', 'yaymatik-automation-for-woocommerce' );
    }

    public function icon(): string {
        return 'refresh-cw';
    }

    public function fields(): array {
        return [];
    }

    public function summary(): string {
        return __( 'When an order is refunded', 'yaymatik-automation-for-woocommerce' );
    }

    public function provides(): array {
        return array_merge( parent::provides(), [ RefundData::ID ] );
    }

    public function listen( callable $emit ): void {
        add_action(
            'woocommerce_refund_created',
            function ( $refund_id, $args = [] ) use ( $emit ) {
                $emit(
                    [
                        'order_id'  => absint( $args['order_id'] ?? 0 ),
                        'refund_id' => (int) $refund_id,
                    ]
                );
            },
            10,
            2
        );
    }

    public function context( array $args ): ?array {
        $context = parent::context( $args );
        $refund  = self::refund( $args );
        return $context && $refund ? $context + [ RefundData::ID => $refund ] : null;
    }

    public function refs( array $args ): ?array {
        $refs   = parent::refs( $args );
        $refund = self::refund( $args );
        return $refs && $refund ? $refs + [ RefundData::ID => new Ref( RefundData::ID, (int) $refund->get_id() ) ] : null;
    }

    /**
     * The event's refund, when it still exists and belongs to the event's order.
     *
     * @param array $args
     */
    private static function refund( array $args ): ?\WC_Order_Refund {
        $refund = wc_get_order( absint( $args['refund_id'] ?? 0 ) );
        return $refund instanceof \WC_Order_Refund && (int) $refund->get_parent_id() === absint( $args['order_id'] ?? 0 ) ? $refund : null;
    }
}
