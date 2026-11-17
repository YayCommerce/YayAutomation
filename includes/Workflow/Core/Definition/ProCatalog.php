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
}
