<?php
/**
 * YayPricing's YayMatik extension: declarations only; the adapter does the work.
 *
 * @package YayPricing
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- another plugin: its own text domain.

/** Registers YayPricing's module on `yaymatik_register`. */
final class YayPricing_YayMatik {

    /** @var YayPricing_YayMatik_Fake_Adapter */
    private $adapter;

    /**
     * @param YayPricing_YayMatik_Fake_Adapter $adapter
     */
    public function __construct( $adapter ) {
        $this->adapter = $adapter;
    }

    /**
     * @param \YayMatik\Api\Registrar $registrar
     */
    public function register( $registrar ) {
        $module = $registrar->module(
            [
                'id'           => 'yaypricing',
                'label'        => 'YayPricing',
                'description'  => __( 'Pricing rules and discounts applied to orders.', 'yaypricing' ),
                'logo'         => plugins_url( 'logo.svg', __FILE__ ),
                'requires_api' => '1.0',
            ]
        );
        if ( ! $module->ok() ) {
            return;
        }

        $module->data_fields(
            'order',
            [
                'yaypricing_rules'          => [
                    'label'   => __( 'Pricing rules applied', 'yaypricing' ),
                    'type'    => 'list',
                    'of'      => 'string',
                    'options' => [ $this->adapter, 'rule_options' ],
                    'value'   => [ $this->adapter, 'order_rules' ],
                    'sample'  => [ 'rule_1' ],
                ],
                'yaypricing_discount_total' => [
                    'label'  => __( 'Pricing rule discount', 'yaypricing' ),
                    'type'   => 'money',
                    'value'  => [ $this->adapter, 'order_discount_total' ],
                    'sample' => 25,
                ],
            ]
        );

        $module->template(
            'yaypricing_large_discount_alert',
            [
                'label'            => __( 'Large rule discount alert', 'yaypricing' ),
                'description'      => __( 'Tell the store team when pricing rules take 100 or more off a paid order, so a rule set too generous gets caught.', 'yaypricing' ),
                'library_category' => 'alerts',
                'created'          => '2026-11-27',
                'steps'            => [
                    [
                        'id'   => 'trigger',
                        'step' => 'order_paid',
                    ],
                    [
                        'id'     => 'check',
                        'step'   => 'condition',
                        'config' => [
                            'match' => 'all',
                            'rules' => [
                                [
                                    'field'    => 'order.yaypricing_discount_total',
                                    'operator' => 'greater_or_equal',
                                    'value'    => '100',
                                ],
                            ],
                        ],
                        'then'   => [
                            [
                                'id'     => 'alert',
                                'step'   => 'send_internal_email',
                                'config' => [
                                    'recipients' => '',
                                    'subject'    => __( 'Order {{ order.number }} got {{ order.yaypricing_discount_total }} off from pricing rules', 'yaypricing' ),
                                    'message'    => __( '<p>Pricing rules took {{ order.yaypricing_discount_total }} off order {{ order.number }} (total {{ order.total }}).</p><p>Rules applied: {{ order.yaypricing_rules }}</p><p><a href="{{ order.admin_url }}">View the order</a></p>', 'yaypricing' ),
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $module->template(
            'yaypricing_rule_cross_sell',
            [
                'label'            => __( 'Cross-sell after a pricing rule', 'yaypricing' ),
                'description'      => __( 'Three days after an order that used a pricing rule is completed, suggest more to buy.', 'yaypricing' ),
                'library_category' => 'post_purchase',
                'created'          => '2026-11-27',
                'before'           => [ __( 'For one rule only, add “Pricing rules applied matches any of” that rule to the condition.', 'yaypricing' ) ],
                'steps'            => [
                    [
                        'id'     => 'trigger',
                        'step'   => 'order_status_changed',
                        'config' => [
                            'from'          => [],
                            'to'            => [ 'completed' ],
                            'only_checkout' => false,
                        ],
                    ],
                    [
                        'id'     => 'check',
                        'step'   => 'condition',
                        'config' => [
                            'match' => 'all',
                            'rules' => [
                                [
                                    'field'    => 'order.yaypricing_discount_total',
                                    'operator' => 'greater_than',
                                    'value'    => '0',
                                ],
                            ],
                        ],
                        'then'   => [
                            [
                                'id'     => 'wait',
                                'step'   => 'wait',
                                'config' => [
                                    'delay' => [
                                        'value' => 3,
                                        'unit'  => 'days',
                                    ],
                                ],
                            ],
                            [
                                'id'     => 'email',
                                'step'   => 'send_email',
                                'config' => [
                                    'to'      => '{{ customer.email }}',
                                    'subject' => __( 'More deals picked for you, {{ customer.first_name | default: "there" }}', 'yaypricing' ),
                                    'heading' => __( 'Thanks for order {{ order.number }}', 'yaypricing' ),
                                    'message' => __( '<p>Hi {{ customer.first_name | default: "there" }},</p><p>You saved with our pricing deals on your last order. There’s more where that came from.</p><p>{{ site.shop_button }}</p>', 'yaypricing' ),
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }
}
