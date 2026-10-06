<?php
namespace YayAutomation\Engine\Blocks\Triggers;

use YayAutomation\Engine\Blocks\Core\Trigger;
use YayAutomation\Engine\Data\CustomerData;
use YayAutomation\Engine\Data\OrderData;
use YayAutomation\Engine\Plugins;

defined( 'ABSPATH' ) || exit;

/** Base for WooCommerce order events. Event args hold at least `order_id`. */
abstract class OrderTrigger extends Trigger {

    /** Statuses of orders not placed yet: auto-drafts and block checkout drafts. */
    protected const DRAFT_STATUSES = [ 'auto-draft', 'new', 'checkout-draft' ];

    public function plugin(): string {
        return Plugins::WOOCOMMERCE;
    }

    public function provides(): array {
        return [ OrderData::ID, CustomerData::ID ];
    }

    public function subject( array $args ): string {
        return 'order:' . (int) ( $args['order_id'] ?? 0 );
    }

    /** The order is also the customer: its billing details work for guests too. */
    public function context( array $args ): ?array {
        $order = self::order( $args['order_id'] ?? 0 );
        return $order ? [
            OrderData::ID    => $order,
            CustomerData::ID => $order,
        ] : null;
    }

    /**
     * A real order (not a refund or subscription), or null.
     *
     * @param mixed $order_id Order id.
     */
    protected static function order( $order_id ): ?\WC_Order {
        $order = wc_get_order( absint( $order_id ) );
        return $order instanceof \WC_Order && 'shop_order' === $order->get_type() ? $order : null;
    }

    protected static function is_draft( string $status ): bool {
        return in_array( $status, self::DRAFT_STATUSES, true );
    }
}
