<?php
namespace YayMatik\Pro\Workflow\DataItems;

use YayMatik\Workflow\Core\Data\MetaKeys;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Pro\Workflow\Core\Email\OrderGrid;
use YayMatik\Workflow\Core\Email\ProductGrid;
use YayMatik\Workflow\DataItems\OrderData;

defined( 'ABSPATH' ) || exit;

/** The order in YayMatik Pro: the free fields plus totals, origin, currency, custom fields and product suggestions. */
final class OrderProData extends OrderData {

    /** Field order in the pickers: Pro's sit beside the free fields they belong with. */
    private const ORDER = [ 'number', 'total', 'subtotal', 'status', 'payment_method', 'shipping_method', 'item_count', 'created_date', 'paid_date', 'completed_date', 'product_ids', 'category_ids', 'coupon_codes', 'billing_country', 'shipping_country', 'created_via', 'currency', 'customer_note', 'discount_total', 'shipping_total', 'refunded_total', 'is_guest', 'meta', 'customer', 'admin_url', 'items', 'has_cross_sells', 'has_upsells', 'cross_sell_products', 'upsell_products', 'has_products_not_reviewed', 'review_products' ];

    protected function define(): array {
        $fields = parent::define() + [
            'created_via'         => [
                'label'   => __( 'Created via', 'yaymatik-automation-for-woocommerce' ),
                'type'    => self::TYPE_ENUM,
                'value'   => fn( \WC_Order $order ) => $order->get_created_via(),
                'options' => [ self::class, 'created_via_options' ],
            ],
            'currency'            => [
                'label'   => __( 'Currency', 'yaymatik-automation-for-woocommerce' ),
                'type'    => self::TYPE_ENUM,
                'value'   => fn( \WC_Order $order ) => $order->get_currency(),
                'options' => [ self::class, 'currencies' ],
            ],
            'discount_total'      => self::money_field( __( 'Discount total', 'yaymatik-automation-for-woocommerce' ), fn( \WC_Order $order ) => (float) $order->get_discount_total() ),
            'shipping_total'      => self::money_field( __( 'Shipping total', 'yaymatik-automation-for-woocommerce' ), fn( \WC_Order $order ) => (float) $order->get_shipping_total() ),
            'refunded_total'      => self::money_field( __( 'Refunded total', 'yaymatik-automation-for-woocommerce' ), fn( \WC_Order $order ) => (float) $order->get_total_refunded() ),
            'meta'                => [
                'label'        => __( 'Custom field', 'yaymatik-automation-for-woocommerce' ),
                'type'         => self::TYPE_META,
                'value'        => fn() => '',
                'merge_tag'    => false,
                'meta'         => [ self::class, 'custom_field' ],
                'meta_blocked' => fn( string $key ) => MetaKeys::order_blocked( new \WC_Order(), $key ),
            ],
            'has_cross_sells'     => [
                'label' => __( 'Order has cross-sell products', 'yaymatik-automation-for-woocommerce' ),
                'type'  => self::TYPE_BOOLEAN,
                'value' => fn( \WC_Order $order ) => (bool) OrderGrid::for_order( $order, OrderGrid::CROSS_SELLS ),
            ],
            'has_upsells'         => [
                'label' => __( 'Order has upsell products', 'yaymatik-automation-for-woocommerce' ),
                'type'  => self::TYPE_BOOLEAN,
                'value' => fn( \WC_Order $order ) => (bool) OrderGrid::for_order( $order, OrderGrid::UPSELLS ),
            ],
            'cross_sell_products' => self::grid_field( __( 'Cross-sell products', 'yaymatik-automation-for-woocommerce' ), OrderGrid::CROSS_SELLS ),
            'upsell_products'     => self::grid_field( __( 'Upsell products', 'yaymatik-automation-for-woocommerce' ), OrderGrid::UPSELLS ),
        ];
        return array_merge( array_intersect_key( array_flip( self::ORDER ), $fields ), $fields );
    }

    public function sample(): array {
        $price = [ self::class, 'sample_price' ];
        return parent::sample() + [
            'created_via'     => [
                'value' => 'checkout',
                'text'  => self::created_via_options()['checkout'],
            ],
            'currency'        => [
                'value' => 'USD',
                'text'  => self::currencies()['USD'] ?? 'United States (US) dollar',
            ],
            'discount_total'  => $price( 0.0 ),
            'shipping_total'  => $price( 5.0 ),
            'refunded_total'  => $price( 0.0 ),
            'has_cross_sells' => (bool) ProductGrid::newest(),
            'has_upsells'     => (bool) ProductGrid::newest(),
        ] + array_fill_keys( [ 'cross_sell_products', 'upsell_products' ], self::sample_grid() );
    }

    /**
     * A product grid email block.
     *
     * @param string $label
     * @param string $kind  `OrderGrid` CROSS_SELLS or UPSELLS.
     */
    private static function grid_field( string $label, string $kind ): array {
        return [
            'label'       => $label,
            'type'        => self::TYPE_STRING,
            'value'       => fn( \WC_Order $order ) => ProductGrid::render( OrderGrid::for_block( $order, $kind ), false ),
            'display'     => fn( \WC_Order $order, bool $html ) => ProductGrid::render( OrderGrid::for_block( $order, $kind ), $html ),
            'conditional' => false,
            'block'       => true,
        ];
    }

    /**
     * @param string   $label
     * @param callable $amount
     */
    private static function money_field( string $label, callable $amount ): array {
        return [
            'label'      => $label,
            'type'       => self::TYPE_NUMBER,
            'value_type' => Type::money(),
            'value'      => $amount,
            'display'    => fn( \WC_Order $order, bool $html ) => self::price( $order, $amount( $order ), $html ),
        ];
    }

    /**
     * A custom field as text; '' for a blocked key, or an array or object value.
     *
     * @param \WC_Order $order
     * @param string    $key
     */
    public static function custom_field( \WC_Order $order, string $key ): string {
        if ( MetaKeys::order_blocked( $order, $key ) ) {
            return '';
        }
        $value = $order->get_meta( $key );
        return is_scalar( $value ) ? (string) $value : '';
    }

    /** Where WooCommerce says an order came from, as value => label. */
    public static function created_via_options(): array {
        return [
            'checkout'  => __( 'Classic checkout', 'yaymatik-automation-for-woocommerce' ),
            'store-api' => __( 'Block checkout', 'yaymatik-automation-for-woocommerce' ),
            'admin'     => __( 'Admin', 'yaymatik-automation-for-woocommerce' ),
            'rest-api'  => __( 'REST API', 'yaymatik-automation-for-woocommerce' ),
        ];
    }

    /** Code => name; empty without WooCommerce. */
    public static function currencies(): array {
        return function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies() : [];
    }

    /** The store's newest products as a grid: what a suggestion block looks like; built once per request. */
    private static function sample_grid(): array {
        static $grid = null;
        if ( null === $grid ) {
            $text = ProductGrid::render( ProductGrid::newest(), false );
            $grid = [
                'value' => $text,
                'text'  => $text,
                'html'  => ProductGrid::render( ProductGrid::newest(), true ),
            ];
        }
        return $grid;
    }
}
