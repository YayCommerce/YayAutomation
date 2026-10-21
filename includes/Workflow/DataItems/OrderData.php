<?php
namespace YayMatik\Workflow\DataItems;

use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\Type;

defined( 'ABSPATH' ) || exit;

/** A WooCommerce order. Context object: \WC_Order. Relations: `customer`, `items`. */
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
                'label' => __( 'Order number', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( \WC_Order $order ) => (string) $order->get_order_number(),
            ],
            'total'           => [
                'label'      => __( 'Order total', 'yaymatik' ),
                'type'       => self::TYPE_NUMBER,
                'value_type' => Type::money(),
                'value'      => fn( \WC_Order $order ) => (float) $order->get_total(),
                'display'    => fn( \WC_Order $order, bool $html ) => self::price( $order, (float) $order->get_total(), $html ),
            ],
            'subtotal'        => [
                'label'      => __( 'Order subtotal', 'yaymatik' ),
                'type'       => self::TYPE_NUMBER,
                'value_type' => Type::money(),
                'value'      => fn( \WC_Order $order ) => (float) $order->get_subtotal(),
                'display'    => fn( \WC_Order $order, bool $html ) => self::price( $order, (float) $order->get_subtotal(), $html ),
            ],
            'status'          => [
                'label'   => __( 'Order status', 'yaymatik' ),
                'type'    => self::TYPE_ENUM,
                'value'   => fn( \WC_Order $order ) => $order->get_status(),
                'options' => [ self::class, 'statuses' ],
            ],
            'payment_method'  => [
                'label'   => __( 'Payment method', 'yaymatik' ),
                'type'    => self::TYPE_ENUM,
                'value'   => fn( \WC_Order $order ) => $order->get_payment_method(),
                'display' => fn( \WC_Order $order, bool $html ) => self::text( $order->get_payment_method_title(), $html ),
                'options' => [ self::class, 'payment_methods' ],
            ],
            'shipping_method' => [
                'label' => __( 'Shipping method', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( \WC_Order $order ) => $order->get_shipping_method(),
            ],
            'item_count'      => [
                'label' => __( 'Item count', 'yaymatik' ),
                'type'  => self::TYPE_NUMBER,
                'value' => fn( \WC_Order $order ) => (float) $order->get_item_count(),
            ],
            'created_date'    => [
                'label'   => __( 'Order date', 'yaymatik' ),
                'type'    => self::TYPE_DATE,
                // WC_DateTime is in the site's timezone, so this is the date the store sees.
                'value'   => fn( \WC_Order $order ) => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
                'display' => fn( \WC_Order $order, bool $html ) => self::text( wc_format_datetime( $order->get_date_created() ), $html ),
            ],
            'customer'        => [
                'label'       => __( 'Customer', 'yaymatik' ),
                'type'        => self::TYPE_STRING,
                'value_type'  => Type::entity( CustomerData::ID ),
                // The billing details on the order, so guests have a customer too.
                'value'       => fn( \WC_Order $order ) => new Ref( CustomerData::ID, 'order:' . $order->get_id() ),
                'conditional' => false,
            ],
            'admin_url'       => [
                'label'       => __( 'Order admin URL', 'yaymatik' ),
                'type'        => self::TYPE_STRING,
                'value'       => fn( \WC_Order $order ) => $order->get_edit_order_url(),
                'conditional' => false,
            ],
            'items'           => [
                'label'       => __( 'Order items', 'yaymatik' ),
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

        return self::items_table( $rows, $order->get_order_item_totals() );
    }

    /**
     * The items table of WooCommerce's order emails.
     *
     * @param string  $rows   Item rows, safe HTML.
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

    /**
     * Made-up order for previews when the store has no paid order yet (labelled "Sample data").
     *
     * @return array Field key => value, or [ 'value', 'text', 'html'? ] when it reads differently.
     */
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
            'number'          => '1001',
            'total'           => $price( 54.0 ),
            'subtotal'        => $price( 49.0 ),
            'status'          => [
                'value' => 'processing',
                'text'  => self::statuses()['processing'] ?? __( 'Processing', 'yaymatik' ),
            ],
            'payment_method'  => [
                'value' => 'card',
                'text'  => __( 'Credit card', 'yaymatik' ),
            ],
            'shipping_method' => __( 'Flat rate', 'yaymatik' ),
            'item_count'      => 3.0,
            'created_date'    => [
                'value' => wp_date( 'Y-m-d' ),
                'text'  => wp_date( (string) get_option( 'date_format', 'F j, Y' ) ),
            ],
            'admin_url'       => admin_url( 'admin.php?page=wc-orders' ),
            'items'           => [
                'value' => [],
                'text'  => implode( "\n", $text ),
                'html'  => self::items_table( $rows, $totals ),
            ],
        ];
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
