<?php
namespace YayMatik\Workflow\DataItems;

use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\MetaKeys;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Email\ProductGrid;
use YayMatik\Workflow\Core\Email\ReviewProducts;

defined( 'ABSPATH' ) || exit;

/** A WooCommerce order (\WC_Order). */
final class OrderData extends DataItem {

    public const ID = 'order';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Order', 'yaymatik' );
    }

    public function description(): string {
        return __( 'The WooCommerce order that started this workflow: its number, totals, status, payment, shipping and items.', 'yaymatik' );
    }

    public function icon(): string {
        return 'shopping-bag';
    }

    /** Slug (no `wc-` prefix) => label; empty without WooCommerce. */
    public static function statuses(): array {
        if ( ! function_exists( 'wc_get_order_statuses' ) ) {
            return [];
        }
        $statuses = [];
        foreach ( wc_get_order_statuses() as $slug => $label ) {
            $statuses[ self::status_slug( $slug ) ] = $label;
        }
        return $statuses;
    }

    /**
     * Status slug as the engine stores it: sanitized, without the `wc-` prefix.
     *
     * @param string $status With or without the prefix.
     */
    public static function status_slug( string $status ): string {
        $status = sanitize_key( $status );
        return 'wc-' === substr( $status, 0, 3 ) ? substr( $status, 3 ) : $status;
    }

    protected function define(): array {
        return [
            'number'                    => [
                'label' => __( 'Order number', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( \WC_Order $order ) => (string) $order->get_order_number(),
            ],
            'total'                     => [
                'label'      => __( 'Order total', 'yaymatik' ),
                'type'       => self::TYPE_NUMBER,
                'value_type' => Type::money(),
                'value'      => fn( \WC_Order $order ) => (float) $order->get_total(),
                'display'    => fn( \WC_Order $order, bool $html ) => self::price( $order, (float) $order->get_total(), $html ),
            ],
            'subtotal'                  => [
                'label'      => __( 'Order subtotal', 'yaymatik' ),
                'type'       => self::TYPE_NUMBER,
                'value_type' => Type::money(),
                'value'      => fn( \WC_Order $order ) => (float) $order->get_subtotal(),
                'display'    => fn( \WC_Order $order, bool $html ) => self::price( $order, (float) $order->get_subtotal(), $html ),
            ],
            'status'                    => [
                'label'   => __( 'Order status', 'yaymatik' ),
                'type'    => self::TYPE_ENUM,
                'value'   => fn( \WC_Order $order ) => $order->get_status(),
                'options' => [ self::class, 'statuses' ],
            ],
            'payment_method'            => [
                'label'   => __( 'Payment method', 'yaymatik' ),
                'type'    => self::TYPE_ENUM,
                'value'   => fn( \WC_Order $order ) => $order->get_payment_method(),
                'display' => fn( \WC_Order $order, bool $html ) => self::text( $order->get_payment_method_title(), $html ),
                'options' => [ self::class, 'payment_methods' ],
            ],
            'shipping_method'           => [
                'label' => __( 'Shipping method', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( \WC_Order $order ) => $order->get_shipping_method(),
            ],
            'item_count'                => [
                'label' => __( 'Item count', 'yaymatik' ),
                'type'  => self::TYPE_NUMBER,
                'value' => fn( \WC_Order $order ) => (float) $order->get_item_count(),
            ],
            'created_date'              => [
                'label'     => __( 'Order date', 'yaymatik' ),
                'type'      => self::TYPE_DATE,
                // WC_DateTime is in the site's timezone, so this is the date the store sees.
                'value'     => fn( \WC_Order $order ) => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
                'timestamp' => fn( \WC_Order $order ) => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : null,
                'display'   => fn( \WC_Order $order, bool $html ) => self::text( wc_format_datetime( $order->get_date_created() ), $html ),
            ],
            'paid_date'                 => self::date_field( __( 'Paid date', 'yaymatik' ), fn( \WC_Order $order ) => $order->get_date_paid() ),
            'completed_date'            => self::date_field( __( 'Completed date', 'yaymatik' ), fn( \WC_Order $order ) => $order->get_date_completed() ),
            'product_ids'               => [
                'label'   => __( 'Products in order', 'yaymatik' ),
                'type'    => self::TYPE_LIST,
                'search'  => 'product',
                // Parents, so a variation matches a rule on its product.
                'value'   => fn( \WC_Order $order ) => array_values( array_unique( array_map( fn( \WC_Order_Item $item ) => (string) $item->get_product_id(), $order->get_items() ) ) ),
                'display' => fn( \WC_Order $order, bool $html ) => self::text( implode( ', ', array_map( fn( \WC_Order_Item $item ) => $item->get_name(), $order->get_items() ) ), $html ),
            ],
            'category_ids'              => [
                'label'   => __( 'Categories in order', 'yaymatik' ),
                'type'    => self::TYPE_LIST,
                'search'  => 'category',
                'value'   => [ self::class, 'category_ids' ],
                'display' => fn( \WC_Order $order, bool $html ) => self::text( implode( ', ', array_filter( array_map( fn( string $id ) => (string) get_term_field( 'name', (int) $id, 'product_cat', 'raw' ), self::category_ids( $order ) ) ) ), $html ),
            ],
            'coupon_codes'              => [
                'label'   => __( 'Coupons used', 'yaymatik' ),
                'type'    => self::TYPE_LIST,
                'search'  => 'coupon',
                'value'   => fn( \WC_Order $order ) => array_values( $order->get_coupon_codes() ),
                'display' => fn( \WC_Order $order, bool $html ) => self::text( implode( ', ', $order->get_coupon_codes() ), $html ),
            ],
            'billing_country'           => self::country_field( __( 'Billing country', 'yaymatik' ), fn( \WC_Order $order ) => $order->get_billing_country() ),
            'shipping_country'          => self::country_field( __( 'Shipping country', 'yaymatik' ), fn( \WC_Order $order ) => $order->get_shipping_country() ),
            'created_via'               => [
                'label'   => __( 'Created via', 'yaymatik' ),
                'type'    => self::TYPE_ENUM,
                'value'   => fn( \WC_Order $order ) => $order->get_created_via(),
                'options' => [ self::class, 'created_via_options' ],
            ],
            'currency'                  => [
                'label'   => __( 'Currency', 'yaymatik' ),
                'type'    => self::TYPE_ENUM,
                'value'   => fn( \WC_Order $order ) => $order->get_currency(),
                'options' => [ self::class, 'currencies' ],
            ],
            'customer_note'             => [
                'label' => __( 'Customer note', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( \WC_Order $order ) => $order->get_customer_note(),
            ],
            'discount_total'            => self::money_field( __( 'Discount total', 'yaymatik' ), fn( \WC_Order $order ) => (float) $order->get_discount_total() ),
            'shipping_total'            => self::money_field( __( 'Shipping total', 'yaymatik' ), fn( \WC_Order $order ) => (float) $order->get_shipping_total() ),
            'refunded_total'            => self::money_field( __( 'Refunded total', 'yaymatik' ), fn( \WC_Order $order ) => (float) $order->get_total_refunded() ),
            'is_guest'                  => [
                'label' => __( 'Is guest', 'yaymatik' ),
                'type'  => self::TYPE_BOOLEAN,
                'value' => fn( \WC_Order $order ) => 0 === (int) $order->get_customer_id(),
            ],
            'meta'                      => [
                'label'        => __( 'Custom field', 'yaymatik' ),
                'type'         => self::TYPE_META,
                'value'        => fn() => '',
                'merge_tag'    => false,
                'meta'         => [ self::class, 'custom_field' ],
                'meta_blocked' => fn( string $key ) => MetaKeys::order_blocked( new \WC_Order(), $key ),
            ],
            'customer'                  => [
                'label'       => __( 'Customer', 'yaymatik' ),
                'type'        => self::TYPE_STRING,
                'value_type'  => Type::entity( CustomerData::ID ),
                // The billing details on the order, so guests have a customer too.
                'value'       => fn( \WC_Order $order ) => new Ref( CustomerData::ID, 'order:' . $order->get_id() ),
                'conditional' => false,
            ],
            'admin_url'                 => [
                'label'       => __( 'Order admin URL', 'yaymatik' ),
                'type'        => self::TYPE_STRING,
                'value'       => fn( \WC_Order $order ) => $order->get_edit_order_url(),
                'conditional' => false,
            ],
            'items'                     => [
                'label'       => __( 'Order items', 'yaymatik' ),
                'type'        => self::TYPE_STRING,
                'value_type'  => Type::list_of( Type::entity( OrderItemData::ID ) ),
                'value'       => fn( \WC_Order $order ) => array_values(
                    array_map( fn( \WC_Order_Item $item ) => new Ref( OrderItemData::ID, $item->get_id() ), $order->get_items() )
                ),
                'display'     => [ self::class, 'items' ],
                'conditional' => false,
            ],
            'has_cross_sells'           => [
                'label' => __( 'Order has cross-sell products', 'yaymatik' ),
                'type'  => self::TYPE_BOOLEAN,
                'value' => fn( \WC_Order $order ) => (bool) ProductGrid::for_order( $order, ProductGrid::CROSS_SELLS ),
            ],
            'has_upsells'               => [
                'label' => __( 'Order has upsell products', 'yaymatik' ),
                'type'  => self::TYPE_BOOLEAN,
                'value' => fn( \WC_Order $order ) => (bool) ProductGrid::for_order( $order, ProductGrid::UPSELLS ),
            ],
            'cross_sell_products'       => self::grid_field( __( 'Cross-sell products', 'yaymatik' ), ProductGrid::CROSS_SELLS ),
            'upsell_products'           => self::grid_field( __( 'Upsell products', 'yaymatik' ), ProductGrid::UPSELLS ),
            'has_products_not_reviewed' => [
                'label' => __( 'Order has products not reviewed', 'yaymatik' ),
                'type'  => self::TYPE_BOOLEAN,
                'value' => fn( \WC_Order $order ) => (bool) ReviewProducts::for_order( $order ),
            ],
            'review_products'           => [
                'label'       => __( 'Products to review', 'yaymatik' ),
                'type'        => self::TYPE_STRING,
                'value'       => fn( \WC_Order $order ) => ReviewProducts::render( ReviewProducts::for_order( $order ), false ),
                'display'     => fn( \WC_Order $order, bool $html ) => ReviewProducts::render( ReviewProducts::for_order( $order ), $html ),
                'conditional' => false,
                'block'       => true,
            ],
        ];
    }

    /**
     * A product grid email block.
     *
     * @param string $label
     * @param string $kind  `ProductGrid` CROSS_SELLS or UPSELLS.
     */
    private static function grid_field( string $label, string $kind ): array {
        return [
            'label'       => $label,
            'type'        => self::TYPE_STRING,
            'value'       => fn( \WC_Order $order ) => ProductGrid::render( ProductGrid::for_order( $order, $kind ), false ),
            'display'     => fn( \WC_Order $order, bool $html ) => ProductGrid::render( ProductGrid::for_order( $order, $kind ), $html ),
            'conditional' => false,
            'block'       => true,
        ];
    }

    /**
     * A date with its real time, so "in the last 3 hours" works.
     *
     * @param string   $label
     * @param callable $date
     */
    private static function date_field( string $label, callable $date ): array {
        return [
            'label'     => $label,
            'type'      => self::TYPE_DATE,
            'value'     => fn( \WC_Order $order ) => $date( $order ) ? $date( $order )->date( 'Y-m-d' ) : '',
            'timestamp' => fn( \WC_Order $order ) => $date( $order ) ? $date( $order )->getTimestamp() : null,
            'display'   => fn( \WC_Order $order, bool $html ) => self::text( wc_format_datetime( $date( $order ) ), $html ),
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
     * @param string   $label
     * @param callable $code
     */
    private static function country_field( string $label, callable $code ): array {
        return [
            'label'   => $label,
            'type'    => self::TYPE_ENUM,
            'value'   => $code,
            'options' => [ CustomerData::class, 'countries' ],
        ];
    }

    /**
     * Term ids of the order's products, with their parent categories.
     *
     * @param \WC_Order $order
     * @return string[]
     */
    public static function category_ids( \WC_Order $order ): array {
        $ids = [];
        foreach ( $order->get_items() as $item ) {
            $ids = array_merge( $ids, wc_get_product_cat_ids( $item->get_product_id() ) );
        }
        return array_values( array_unique( array_map( 'strval', $ids ) ) );
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
            'checkout'  => __( 'Classic checkout', 'yaymatik' ),
            'store-api' => __( 'Block checkout', 'yaymatik' ),
            'admin'     => __( 'Admin', 'yaymatik' ),
            'rest-api'  => __( 'REST API', 'yaymatik' ),
        ];
    }

    /** Code => name; empty without WooCommerce. */
    public static function currencies(): array {
        return function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies() : [];
    }

    /** Enabled payment gateways as id => title. */
    public static function payment_methods(): array {
        if ( ! function_exists( 'WC' ) ) {
            return [];
        }
        $methods = [];
        foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
            if ( 'yes' === $gateway->enabled ) {
                $methods[ $id ] = wp_strip_all_tags( $gateway->get_title() );
            }
        }
        return $methods;
    }

    /**
     * The order's items with totals, like WooCommerce's own order emails: a table in HTML, lines in text.
     *
     * @param \WC_Order $order
     * @param bool      $html
     */
    public static function items( \WC_Order $order, bool $html ): string {
        $rows = wc_get_email_order_items(
            $order,
            [
                'plain_text'    => ! $html,
                'sent_to_admin' => true,
            ]
        );

        if ( ! $html ) {
            $text = trim( wp_strip_all_tags( $rows ) );
            foreach ( $order->get_order_item_totals() as $total ) {
                $text .= "\n" . wp_strip_all_tags( $total['label'] . ' ' . $total['value'] );
            }
            return html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
        }

        return self::items_table( $rows, $order->get_order_item_totals() );
    }

    /**
     * The items table of WooCommerce's order emails.
     *
     * @param string  $rows   Safe HTML.
     * @param array[] $totals [ [ 'label' => HTML, 'value' => HTML ], … ].
     */
    private static function items_table( string $rows, array $totals ): string {
        $cell = 'class="td" style="text-align:left;"';
        $foot = '';
        foreach ( $totals as $total ) {
            $foot .= '<tr><th ' . $cell . ' scope="row" colspan="2">' . wp_kses_post( $total['label'] ) . '</th><td ' . $cell . '>' . wp_kses_post( $total['value'] ) . '</td></tr>';
        }
        $table = '<table class="td" cellspacing="0" cellpadding="6" border="1" style="width:100%;">'
            . '<thead><tr><th ' . $cell . ' scope="col">' . esc_html__( 'Product', 'yaymatik' ) . '</th>'
            . '<th ' . $cell . ' scope="col">' . esc_html__( 'Quantity', 'yaymatik' ) . '</th>'
            . '<th ' . $cell . ' scope="col">' . esc_html__( 'Price', 'yaymatik' ) . '</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody><tfoot>' . $foot . '</tfoot></table>';
        // One line: the email body goes through wpautop(), which would put <br> between table rows.
        return trim( (string) preg_replace( '/\s*\n\s*/', ' ', $table ) );
    }

    /** @return array Field key => value, or [ 'value', 'text', 'html'? ] when it reads differently. */
    public function sample(): array {
        $price = fn( float $amount ) => [
            'value' => $amount,
            'text'  => html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ),
            'html'  => wc_price( $amount ),
        ];
        $lines = [
            [ __( 'Sample product', 'yaymatik' ), 1, 29.0 ],
            [ __( 'Another sample product', 'yaymatik' ), 2, 20.0 ],
        ];
        $cell  = 'class="td" style="text-align:left;"';
        $rows  = '';
        $text  = [];
        foreach ( $lines as list( $name, $quantity, $amount ) ) {
            $rows  .= '<tr><td ' . $cell . '>' . esc_html( $name ) . '</td><td ' . $cell . '>' . (int) $quantity . '</td><td ' . $cell . '>' . $price( $amount )['html'] . '</td></tr>';
            $text[] = $name . ' ×' . $quantity . ' ' . $price( $amount )['text'];
        }
        $totals = [
            [
                'label' => __( 'Subtotal:', 'yaymatik' ),
                'value' => $price( 49.0 )['html'],
            ],
            [
                'label' => __( 'Total:', 'yaymatik' ),
                'value' => $price( 54.0 )['html'],
            ],
        ];
        $text[] = __( 'Subtotal:', 'yaymatik' ) . ' ' . $price( 49.0 )['text'];
        $text[] = __( 'Total:', 'yaymatik' ) . ' ' . $price( 54.0 )['text'];

        return [
            'number'                    => '1001',
            'total'                     => $price( 54.0 ),
            'subtotal'                  => $price( 49.0 ),
            'status'                    => [
                'value' => 'processing',
                'text'  => self::statuses()['processing'] ?? __( 'Processing', 'yaymatik' ),
            ],
            'payment_method'            => [
                'value' => 'card',
                'text'  => __( 'Credit card', 'yaymatik' ),
            ],
            'shipping_method'           => __( 'Flat rate', 'yaymatik' ),
            'item_count'                => 3.0,
            'created_date'              => [
                'value' => wp_date( 'Y-m-d' ),
                'text'  => wp_date( (string) get_option( 'date_format', 'F j, Y' ) ),
            ],
            'paid_date'                 => [
                'value' => wp_date( 'Y-m-d' ),
                'text'  => wp_date( (string) get_option( 'date_format', 'F j, Y' ) ),
            ],
            'completed_date'            => [
                'value' => wp_date( 'Y-m-d' ),
                'text'  => wp_date( (string) get_option( 'date_format', 'F j, Y' ) ),
            ],
            'product_ids'               => [
                'value' => [ '1', '2' ],
                'text'  => __( 'Sample product', 'yaymatik' ) . ', ' . __( 'Another sample product', 'yaymatik' ),
            ],
            'category_ids'              => [
                'value' => [ '1' ],
                'text'  => __( 'Sample category', 'yaymatik' ),
            ],
            'coupon_codes'              => [
                'value' => [ 'ymsample' ],
                'text'  => 'ymsample',
            ],
            'billing_country'           => [
                'value' => 'US',
                'text'  => CustomerData::countries()['US'] ?? 'United States (US)',
            ],
            'shipping_country'          => [
                'value' => 'US',
                'text'  => CustomerData::countries()['US'] ?? 'United States (US)',
            ],
            'created_via'               => [
                'value' => 'checkout',
                'text'  => self::created_via_options()['checkout'],
            ],
            'currency'                  => [
                'value' => 'USD',
                'text'  => self::currencies()['USD'] ?? 'United States (US) dollar',
            ],
            'customer_note'             => __( 'Please leave it at the door.', 'yaymatik' ),
            'discount_total'            => $price( 0.0 ),
            'shipping_total'            => $price( 5.0 ),
            'refunded_total'            => $price( 0.0 ),
            'is_guest'                  => false,
            'admin_url'                 => admin_url( 'admin.php?page=wc-orders' ),
            'items'                     => [
                'value' => [],
                'text'  => implode( "\n", $text ),
                'html'  => self::items_table( $rows, $totals ),
            ],
            'has_cross_sells'           => (bool) ProductGrid::newest(),
            'has_upsells'               => (bool) ProductGrid::newest(),
            'has_products_not_reviewed' => (bool) ProductGrid::newest(),
            'review_products'           => self::sample_reviews(),
        ] + array_fill_keys( [ 'cross_sell_products', 'upsell_products' ], self::sample_grid() );
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

    /** The store's newest products as review rows; built once per request. */
    private static function sample_reviews(): array {
        static $rows = null;
        if ( null === $rows ) {
            $text = ReviewProducts::render( ProductGrid::newest(), false );
            $rows = [
                'value' => $text,
                'text'  => $text,
                'html'  => ReviewProducts::render( ProductGrid::newest(), true ),
            ];
        }
        return $rows;
    }

    /**
     * An amount in the order's currency: HTML from wc_price(), or the same as plain text.
     *
     * @param \WC_Order $order
     * @param float     $amount
     * @param bool      $html
     */
    public static function price( \WC_Order $order, float $amount, bool $html ): string {
        $price = wc_price( $amount, [ 'currency' => $order->get_currency() ] );
        return $html ? $price : html_entity_decode( wp_strip_all_tags( $price ), ENT_QUOTES, 'UTF-8' );
    }
}
