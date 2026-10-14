<?php
namespace YayAutomation\Workflow\Data;

defined( 'ABSPATH' ) || exit;

/**
 * One line of an order, reached through `order.items`. Context object: \WC_Order_Item_Product.
 * Not offered in "Insert variable": it isn't something a trigger supplies.
 */
final class OrderItemData extends DataItem {

    public const ID = 'order_item';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Order item', 'yay-automation' );
    }

    public function description(): string {
        return __( 'One line of an order: the product name, quantity and line total.', 'yay-automation' );
    }

    public function icon(): string {
        return 'package';
    }

    protected function define(): array {
        return [
            'name'     => [
                'label' => __( 'Item name', 'yay-automation' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( \WC_Order_Item $item ) => $item->get_name(),
            ],
            'quantity' => [
                'label' => __( 'Item quantity', 'yay-automation' ),
                'type'  => self::TYPE_NUMBER,
                'value' => fn( \WC_Order_Item $item ) => (float) $item->get_quantity(),
            ],
            'total'    => [
                'label'      => __( 'Item total', 'yay-automation' ),
                'type'       => self::TYPE_NUMBER,
                'value_type' => Type::money(),
                'value'      => fn( \WC_Order_Item $item ) => (float) $item->get_total(),
                'display'    => function ( \WC_Order_Item $item, bool $html ) {
                    $order = $item->get_order();
                    return $order instanceof \WC_Order ? OrderData::price( $order, (float) $item->get_total(), $html ) : (string) $item->get_total();
                },
            ],
        ];
    }
}
