<?php
/**
 * YayPricing's fake adapter: rules read from the order's own discount, no YayPricing code.
 *
 * @package YayPricing
 */

defined( 'ABSPATH' ) || exit;

/** Stands in for YayPricing until its real adapter is written. */
final class YayPricing_YayMatik_Fake_Adapter {

    /** Rule id => name. */
    public function rule_options(): array {
        return [
            'rule_1' => 'Buy 2, get 10% off',
            'rule_2' => 'Bulk discount',
        ];
    }

    /**
     * Rules applied to the order: the first rule when it has any discount.
     *
     * @param \WC_Order $order
     * @return string[]
     */
    public function order_rules( $order ): array {
        return $this->order_discount_total( $order ) > 0 ? [ 'rule_1' ] : [];
    }

    /**
     * @param \WC_Order $order
     */
    public function order_discount_total( $order ): float {
        return (float) $order->get_discount_total();
    }
}
