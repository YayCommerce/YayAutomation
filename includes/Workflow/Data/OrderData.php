<?php
namespace YayAutomation\Workflow\Data;

defined( 'ABSPATH' ) || exit;

/** A WooCommerce order. Context object: \WC_Order. Relations: `customer`, `items`. */
final class OrderData extends DataItem {

    public const ID = 'order';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Order', 'yay-automation' );
    }

    public function description(): string {
        return __( 'The WooCommerce order that started this workflow: its number, totals, status, payment, shipping and items.', 'yay-automation' );
    }

    /**
     * Registered order statuses as slug (no `wc-` prefix) => label. Empty without WooCommerce.
     */
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
     * @param string $status Raw status, with or without the prefix.
     */
    public static function status_slug( string $status ): string {
        $status = sanitize_key( $status );
        return 'wc-' === substr( $status, 0, 3 ) ? substr( $status, 3 ) : $status;
    }

    protected function define(): array {
        return [
            'number'          => [
                'label' => __( 'Order number', 'yay-automation' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( \WC_Order $order ) => (string) $order->get_order_number(),
            ],
            'total'           => [
                'label'      => __( 'Order total', 'yay-automation' ),
                'type'       => self::TYPE_NUMBER,
                'value_type' => Type::money(),
                'value'      => fn( \WC_Order $order ) => (float) $order->get_total(),
                'display'    => fn( \WC_Order $order, bool $html ) => self::price( $order, (float) $order->get_total(), $html ),
            ],
            'subtotal'        => [
                'label'      => __( 'Order subtotal', 'yay-automation' ),
                'type'       => self::TYPE_NUMBER,
                'value_type' => Type::money(),
                'value'      => fn( \WC_Order $order ) => (float) $order->get_subtotal(),
                'display'    => fn( \WC_Order $order, bool $html ) => self::price( $order, (float) $order->get_subtotal(), $html ),
            ],
            'status'          => [
                'label'   => __( 'Order status', 'yay-automation' ),
                'type'    => self::TYPE_ENUM,
                'value'   => fn( \WC_Order $order ) => $order->get_status(),
                'options' => [ self::class, 'statuses' ],
            ],
            'payment_method'  => [
                'label'   => __( 'Payment method', 'yay-automation' ),
                'type'    => self::TYPE_ENUM,
                'value'   => fn( \WC_Order $order ) => $order->get_payment_method(),
                'display' => fn( \WC_Order $order, bool $html ) => self::text( $order->get_payment_method_title(), $html ),
                'options' => [ self::class, 'payment_methods' ],
            ],
            'shipping_method' => [
                'label' => __( 'Shipping method', 'yay-automation' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( \WC_Order $order ) => $order->get_shipping_method(),
            ],
            'item_count'      => [
                'label' => __( 'Item count', 'yay-automation' ),
                'type'  => self::TYPE_NUMBER,
                'value' => fn( \WC_Order $order ) => (float) $order->get_item_count(),
            ],
            'created_date'    => [
                'label'   => __( 'Order date', 'yay-automation' ),
                'type'    => self::TYPE_DATE,
                // WC_DateTime is in the site's timezone, so this is the date the store sees.
                'value'   => fn( \WC_Order $order ) => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
                'display' => fn( \WC_Order $order, bool $html ) => self::text( wc_format_datetime( $order->get_date_created() ), $html ),
            ],
            'customer'        => [
                'label'       => __( 'Customer', 'yay-automation' ),
                'type'        => self::TYPE_STRING,
                'value_type'  => Type::entity( CustomerData::ID ),
                // The billing details on the order, so guests have a customer too.
                'value'       => fn( \WC_Order $order ) => new Ref( CustomerData::ID, 'order:' . $order->get_id() ),
                'conditional' => false,
            ],
            'admin_url'       => [
                'label'       => __( 'Order admin URL', 'yay-automation' ),
                'type'        => self::TYPE_STRING,
                'value'       => fn( \WC_Order $order ) => $order->get_edit_order_url(),
                'conditional' => false,
            ],
            'items'           => [
                'label'       => __( 'Order items', 'yay-automation' ),
                'type'        => self::TYPE_STRING,
                'value_type'  => Type::list_of( Type::entity( OrderItemData::ID ) ),
                'value'       => fn( \WC_Order $order ) => array_values(
                    array_map( fn( \WC_Order_Item $item ) => new Ref( OrderItemData::ID, $item->get_id() ), $order->get_items() )
                ),
                'display'     => [ self::class, 'items' ],
                'conditional' => false,
            ],
        ];
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
     * @param \WC_Order $order Order.
     * @param bool      $html  HTML table instead of plain text.
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

        $cell   = 'class="td" style="text-align:left;"';
        $totals = '';
        foreach ( $order->get_order_item_totals() as $total ) {
            $totals .= '<tr><th ' . $cell . ' scope="row" colspan="2">' . wp_kses_post( $total['label'] ) . '</th><td ' . $cell . '>' . wp_kses_post( $total['value'] ) . '</td></tr>';
        }
        $table = '<table class="td" cellspacing="0" cellpadding="6" border="1" style="width:100%;">'
            . '<thead><tr><th ' . $cell . ' scope="col">' . esc_html__( 'Product', 'yay-automation' ) . '</th>'
            . '<th ' . $cell . ' scope="col">' . esc_html__( 'Quantity', 'yay-automation' ) . '</th>'
            . '<th ' . $cell . ' scope="col">' . esc_html__( 'Price', 'yay-automation' ) . '</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody><tfoot>' . $totals . '</tfoot></table>';
        // One line: the email body goes through wpautop(), which would put <br> between table rows.
        return trim( (string) preg_replace( '/\s*\n\s*/', ' ', $table ) );
    }

    /**
     * An amount in the order's currency: HTML from wc_price(), or the same as plain text.
     *
     * @param \WC_Order $order  Order.
     * @param float     $amount Amount.
     * @param bool      $html   HTML instead of plain text.
     */
    public static function price( \WC_Order $order, float $amount, bool $html ): string {
        $price = wc_price( $amount, [ 'currency' => $order->get_currency() ] );
        return $html ? $price : html_entity_decode( wp_strip_all_tags( $price ), ENT_QUOTES, 'UTF-8' );
    }

    private static function text( string $text, bool $html ): string {
        return $html ? esc_html( $text ) : $text;
    }
}
