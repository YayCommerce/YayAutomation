<?php
namespace YayMatik\Workflow\Core\Definition;

defined( 'ABSPATH' ) || exit;

/** What YayMatik Pro adds, by name only: no behaviour lives here. */
final class ProCatalog {

    /** Step id => kind. */
    public const STEPS = [
        'customer_inactive'            => 'trigger',
        'customer_order_count_reaches' => 'trigger',
        'customer_total_spend_reaches' => 'trigger',
        'wait_until'                   => 'action',
        'resend_wc_email'              => 'action',
        'update_order_meta'            => 'action',
        'update_customer_meta'         => 'action',
        'change_customer_role'         => 'action',
    ];

    /** Data field keys. */
    public const FIELDS = [
        'customer.paid_order_count',
        'customer.total_spent',
        'customer.last_order_date',
        'customer.registered_date',
        'customer.role',
        'customer.purchased_product_ids',
        'customer.meta',
        'order.discount_total',
        'order.shipping_total',
        'order.refunded_total',
        'order.created_via',
        'order.currency',
        'order.has_cross_sells',
        'order.has_upsells',
        'order.cross_sell_products',
        'order.upsell_products',
        'order.meta',
    ];
}
