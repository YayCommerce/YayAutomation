<?php
namespace YayMatik\Workflow\DataItems;

use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Email\ProductGrid;
use YayMatik\Workflow\Core\Email\ReviewProducts;

defined( 'ABSPATH' ) || exit;

/** A WooCommerce order (\WC_Order). */
class OrderData extends DataItem {

    public const ID = 'order';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Order', 'yaymatik-automation-for-woocommerce' );
    }

    public function description(): string {
        return __( 'The WooCommerce order that started this workflow: its number, totals, status, payment, shipping and items.', 'yaymatik-automation-for-woocommerce' );
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
                'label' => __( 'Order number', 'yaymatik-automation-for-woocommerce' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( \WC_Order $order ) => (string) $order->get_order_number(),
            ],
            'total'                     => [
                'label'      => __( 'Order total', 'yaymatik-automation-for-woocommerce' ),
                'type'       => self::TYPE_NUMBER,
                'value_type' => Type::money(),
                'value'      => fn( \WC_Order $order ) => (float) $order->get_total(),
                'display'    => fn( \WC_Order $order, bool $html ) => self::price( $order, (float) $order->get_total(), $html ),
            ],
            'subtotal'                  => [
                'label'      => __( 'Order subtotal', 'yaymatik-automation-for-woocommerce' ),
                'type'       => self::TYPE_NUMBER,
                'value_type' => Type::money(),
                'value'      => fn( \WC_Order $order ) => (float) $order->get_subtotal(),
                'display'    => fn( \WC_Order $order, bool $html ) => self::price( $order, (float) $order->get_subtotal(), $html ),
            ],
            'status'                    => [
                'label'   => __( 'Order status', 'yaymatik-automation-for-woocommerce' ),
                'type'    => self::TYPE_ENUM,
                'value'   => fn( \WC_Order $order ) => $order->get_status(),
                'options' => [ self::class, 'statuses' ],
            ],
            'payment_method'            => [
                'label'   => __( 'Payment method', 'yaymatik-automation-for-woocommerce' ),
                'type'    => self::TYPE_ENUM,
                'value'   => fn( \WC_Order $order ) => $order->get_payment_method(),
                'display' => fn( \WC_Order $order, bool $html ) => self::text( $order->get_payment_method_title(), $html ),
                'options' => [ self::class, 'payment_methods' ],
            ],
            'shipping_method'           => [
                'label' => __( 'Shipping method', 'yaymatik-automation-for-woocommerce' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( \WC_Order $order ) => $order->get_shipping_method(),
            ],
            'item_count'                => [
                'label' => __( 'Item count', 'yaymatik-automation-for-woocommerce' ),
                'type'  => self::TYPE_NUMBER,
                'value' => fn( \WC_Order $order ) => (float) $order->get_item_count(),
            ],
            'created_date'              => [
                'label'     => __( 'Order date', 'yaymatik-automation-for-woocommerce' ),
                'type'      => self::TYPE_DATE,
                // WC_DateTime is in the site's timezone, so this is the date the store sees.
                'value'     => fn( \WC_Order $order ) => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
                'timestamp' => fn( \WC_Order $order ) => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : null,
                'display'   => fn( \WC_Order $order, bool $html ) => self::text( wc_format_datetime( $order->get_date_created() ), $html ),
            ],
            'paid_date'                 => self::date_field( __( 'Paid date', 'yaymatik-automation-for-woocommerce' ), fn( \WC_Order $order ) => $order->get_date_paid() ),
            'completed_date'            => self::date_field( __( 'Completed date', 'yaymatik-automation-for-woocommerce' ), fn( \WC_Order $order ) => $order->get_date_completed() ),
            'product_ids'               => [
                'label'   => __( 'Products in order', 'yaymatik-automation-for-woocommerce' ),
                'type'    => self::TYPE_LIST,
                'search'  => 'product',
                // Parents, so a variation matches a rule on its product.
                'value'   => fn( \WC_Order $order ) => array_values( array_unique( array_map( fn( \WC_Order_Item $item ) => (string) $item->get_product_id(), $order->get_items() ) ) ),
                'display' => fn( \WC_Order $order, bool $html ) => self::text( implode( ', ', array_map( fn( \WC_Order_Item $item ) => $item->get_name(), $order->get_items() ) ), $html ),
            ],
            'category_ids'              => [
                'label'   => __( 'Categories in order', 'yaymatik-automation-for-woocommerce' ),
                'type'    => self::TYPE_LIST,
                'search'  => 'category',
                'value'   => [ self::class, 'category_ids' ],
                'display' => fn( \WC_Order $order, bool $html ) => self::text( implode( ', ', array_filter( array_map( fn( string $id ) => (string) get_term_field( 'name', (int) $id, 'product_cat', 'raw' ), self::category_ids( $order ) ) ) ), $html ),
            ],
            'coupon_codes'              => [
                'label'   => __( 'Coupons used', 'yaymatik-automation-for-woocommerce' ),
                'type'    => self::TYPE_LIST,
                'search'  => 'coupon',
                'value'   => fn( \WC_Order $order ) => array_values( $order->get_coupon_codes() ),
                'display' => fn( \WC_Order $order, bool $html ) => self::text( implode( ', ', $order->get_coupon_codes() ), $html ),
            ],
            'billing_country'           => self::country_field( __( 'Billing country', 'yaymatik-automation-for-woocommerce' ), fn( \WC_Order $order ) => $order->get_billing_country() ),
            'shipping_country'          => self::country_field( __( 'Shipping country', 'yaymatik-automation-for-woocommerce' ), fn( \WC_Order $order ) => $order->get_shipping_country() ),
            'customer_note'             => [
                'label' => __( 'Customer note', 'yaymatik-automation-for-woocommerce' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( \WC_Order $order ) => $order->get_customer_note(),
            ],
            'is_guest'                  => [
                'label' => __( 'Is guest', 'yaymatik-automation-for-woocommerce' ),
                'type'  => self::TYPE_BOOLEAN,
                'value' => fn( \WC_Order $order ) => 0 === (int) $order->get_customer_id(),
            ],
            'customer'                  => [
                'label'       => __( 'Customer', 'yaymatik-automation-for-woocommerce' ),
                'type'        => self::TYPE_STRING,
                'value_type'  => Type::entity( CustomerData::ID ),
                // The billing details on the order, so guests have a customer too.
                'value'       => fn( \WC_Order $order ) => new Ref( CustomerData::ID, 'order:' . $order->get_id() ),
                'conditional' => false,
            ],
            'admin_url'                 => [
                'label'       => __( 'Order admin URL', 'yaymatik-automation-for-woocommerce' ),
                'type'        => self::TYPE_STRING,
                'value'       => fn( \WC_Order $order ) => $order->get_edit_order_url(),
                'conditional' => false,
            ],
            'items'                     => [
                'label'       => __( 'Order items', 'yaymatik-automation-for-woocommerce' ),
                'type'        => self::TYPE_STRING,
                'value_type'  => Type::list_of( Type::entity( OrderItemData::ID ) ),
                'value'       => fn( \WC_Order $order ) => array_values(
                    array_map( fn( \WC_Order_Item $item ) => new Ref( OrderItemData::ID, $item->get_id() ), $order->get_items() )
                ),
                'display'     => [ self::class, 'items' ],
                'conditional' => false,
            ],
            'has_products_not_reviewed' => [
                'label' => __( 'Order has products not reviewed', 'yaymatik-automation-for-woocommerce' ),
                'type'  => self::TYPE_BOOLEAN,
                'value' => fn( \WC_Order $order ) => (bool) ReviewProducts::for_order( $order ),
            ],
            'review_products'           => [
                'label'       => __( 'Products to review', 'yaymatik-automation-for-woocommerce' ),
                'type'        => self::TYPE_STRING,
                'value'       => fn( \WC_Order $order ) => ReviewProducts::render( ReviewProducts::for_order( $order ), false ),
                'display'     => fn( \WC_Order $order, bool $html ) => ReviewProducts::render( ReviewProducts::for_order( $order ), $html ),
                'conditional' => false,
                'block'       => true,
            ],
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
            . '<thead><tr><th ' . $cell . ' scope="col">' . esc_html__( 'Product', 'yaymatik-automation-for-woocommerce' ) . '</th>'
            . '<th ' . $cell . ' scope="col">' . esc_html__( 'Quantity', 'yaymatik-automation-for-woocommerce' ) . '</th>'
            . '<th ' . $cell . ' scope="col">' . esc_html__( 'Price', 'yaymatik-automation-for-woocommerce' ) . '</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody><tfoot>' . $foot . '</tfoot></table>';
        // One line: the email body goes through wpautop(), which would put <br> between table rows.
        return trim( (string) preg_replace( '/\s*\n\s*/', ' ', $table ) );
    }

    /** @return array Field key => value, or [ 'value', 'text', 'html'? ] when it reads differently. */
    public function sample(): array {
        $price = [ self::class, 'sample_price' ];
        $lines = [
            [ __( 'Sample product', 'yaymatik-automation-for-woocommerce' ), 1, 29.0 ],
            [ __( 'Another sample product', 'yaymatik-automation-for-woocommerce' ), 2, 20.0 ],
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
                'label' => __( 'Subtotal:', 'yaymatik-automation-for-woocommerce' ),
                'value' => $price( 49.0 )['html'],
            ],
            [
                'label' => __( 'Total:', 'yaymatik-automation-for-woocommerce' ),
                'value' => $price( 54.0 )['html'],
            ],
        ];
        $text[] = __( 'Subtotal:', 'yaymatik-automation-for-woocommerce' ) . ' ' . $price( 49.0 )['text'];
        $text[] = __( 'Total:', 'yaymatik-automation-for-woocommerce' ) . ' ' . $price( 54.0 )['text'];

        return [
            'number'                    => '1001',
            'total'                     => $price( 54.0 ),
            'subtotal'                  => $price( 49.0 ),
            'status'                    => [
                'value' => 'processing',
                'text'  => self::statuses()['processing'] ?? __( 'Processing', 'yaymatik-automation-for-woocommerce' ),
            ],
            'payment_method'            => [
                'value' => 'card',
                'text'  => __( 'Credit card', 'yaymatik-automation-for-woocommerce' ),
            ],
            'shipping_method'           => __( 'Flat rate', 'yaymatik-automation-for-woocommerce' ),
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
                'text'  => __( 'Sample product', 'yaymatik-automation-for-woocommerce' ) . ', ' . __( 'Another sample product', 'yaymatik-automation-for-woocommerce' ),
            ],
            'category_ids'              => [
                'value' => [ '1' ],
                'text'  => __( 'Sample category', 'yaymatik-automation-for-woocommerce' ),
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
            'customer_note'             => __( 'Please leave it at the door.', 'yaymatik-automation-for-woocommerce' ),
            'is_guest'                  => false,
            'admin_url'                 => admin_url( 'admin.php?page=wc-orders' ),
            'items'                     => [
                'value' => [],
                'text'  => implode( "\n", $text ),
                'html'  => self::items_table( $rows, $totals ),
            ],
            'has_products_not_reviewed' => (bool) ProductGrid::newest(),
            'review_products'           => self::sample_reviews(),
        ];
    }

    /**
     * A sample amount as a field reads it.
     *
     * @param float $amount
     */
    protected static function sample_price( float $amount ): array {
        return [
            'value' => $amount,
            'text'  => html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ),
            'html'  => wc_price( $amount ),
        ];
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
