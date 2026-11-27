<?php
namespace YayMatik\Workflow\DataItems;

use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\Type;

defined( 'ABSPATH' ) || exit;

/** One refund of an order (\WC_Order_Refund), supplied by Order refunded. */
final class RefundData extends DataItem {

    public const ID = 'refund';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Refund', 'yaymatik-automation-for-woocommerce' );
    }

    public function description(): string {
        return __( 'The refund that started the run: amount, reason and whether it was the full order.', 'yaymatik-automation-for-woocommerce' );
    }

    public function icon(): string {
        return 'receipt';
    }

    protected function define(): array {
        return [
            'amount'  => [
                'label'      => __( 'Refund amount', 'yaymatik-automation-for-woocommerce' ),
                'type'       => self::TYPE_NUMBER,
                'value_type' => Type::money(),
                'value'      => fn( \WC_Order_Refund $refund ) => (float) $refund->get_amount(),
                'display'    => fn( \WC_Order_Refund $refund, bool $html ) => self::price( (float) $refund->get_amount(), (string) $refund->get_currency(), $html ),
            ],
            'reason'  => [
                'label' => __( 'Refund reason', 'yaymatik-automation-for-woocommerce' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( \WC_Order_Refund $refund ) => (string) $refund->get_reason(),
            ],
            'is_full' => [
                'label' => __( 'Full refund', 'yaymatik-automation-for-woocommerce' ),
                'type'  => self::TYPE_BOOLEAN,
                'value' => fn( \WC_Order_Refund $refund ) => self::is_full( $refund ),
            ],
        ];
    }

    public function sample(): array {
        return [
            'amount'  => [
                'value' => 20.0,
                'text'  => self::price( 20.0, '', false ),
                'html'  => self::price( 20.0, '', true ),
            ],
            'reason'  => __( 'Damaged in transit', 'yaymatik-automation-for-woocommerce' ),
            'is_full' => false,
        ];
    }

    /**
     * Whether this refund and the ones before it cover the order total.
     *
     * @param \WC_Order_Refund $refund
     */
    private static function is_full( \WC_Order_Refund $refund ): bool {
        // Read from the refunds up to this one: WooCommerce stores its own decision on none of them, and a later refund mustn't change this one's answer.
        $order = wc_get_order( $refund->get_parent_id() );
        if ( ! $order instanceof \WC_Order ) {
            return false;
        }
        $refunded = 0.0;
        foreach ( $order->get_refunds() as $earlier ) {
            if ( $earlier->get_id() <= $refund->get_id() ) {
                $refunded += (float) $earlier->get_amount();
            }
        }
        return round( (float) $order->get_total() - $refunded, wc_get_price_decimals() ) <= 0;
    }

    /**
     * A price in the refund's currency; '' = the store's.
     *
     * @param float  $amount
     * @param string $currency
     * @param bool   $html
     */
    private static function price( float $amount, string $currency, bool $html ): string {
        $price = wc_price( $amount, $currency ? [ 'currency' => $currency ] : [] );
        return $html ? $price : html_entity_decode( wp_strip_all_tags( $price ), ENT_QUOTES, 'UTF-8' );
    }
}
