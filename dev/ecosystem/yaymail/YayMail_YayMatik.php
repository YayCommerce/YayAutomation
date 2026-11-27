<?php
/**
 * YayMail's YayMatik extension: declarations only; the adapter does the work.
 *
 * @package YayMail
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- another plugin: its own text domain.

/** Registers YayMail's module on `yaymatik_register`. */
final class YayMail_YayMatik {

    /** @var YayMail_YayMatik_Fake_Adapter */
    private $adapter;

    /**
     * @param YayMail_YayMatik_Fake_Adapter $adapter
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
                'id'           => 'yaymail',
                'label'        => 'YayMail',
                'description'  => __( 'Order emails designed in YayMail.', 'yaymail' ),
                'logo'         => plugins_url( 'logo.svg', __FILE__ ),
                'requires_api' => '1.0',
            ]
        );
        if ( ! $module->ok() ) {
            return;
        }

        $module->action(
            'yaymail_send_email',
            [
                'label'       => __( 'Send email designed in YayMail', 'yaymail' ),
                'description' => __( 'Sends the customer an email designed in YayMail.', 'yaymail' ),
                'category'    => 'email',
                'settings'    => [
                    'subject'   => [
                        'type'      => 'text',
                        'label'     => __( 'Subject', 'yaymail' ),
                        'variables' => true,
                        'required'  => true,
                    ],
                    'design_id' => [
                        'type'     => 'select',
                        'label'    => __( 'Design', 'yaymail' ),
                        'options'  => [ $this->adapter, 'design_options' ],
                        'required' => true,
                    ],
                ],
                /* translators: {{ config.subject }}: the email's subject; keep {{ … }} as it is. */
                'summary'     => __( 'Email “{{ config.subject }}”', 'yaymail' ),
                'email'       => [
                    'audience'  => 'customer',
                    'recipient' => 'customer',
                    'build'     => [ $this->adapter, 'build_email' ],
                ],
            ]
        );

        $before = [ __( 'Pick the email’s design in YayMail after you add the template.', 'yaymail' ) ];

        $module->template(
            'yaymail_order_paid_thank_you',
            [
                'label'            => __( 'Thank-you email designed in YayMail', 'yaymail' ),
                'description'      => __( 'Thank customers when their order is paid, with an email designed in YayMail.', 'yaymail' ),
                'library_category' => 'post_purchase',
                'created'          => '2026-11-27',
                'before'           => $before,
                'steps'            => [
                    [
                        'id'   => 'trigger',
                        'step' => 'order_paid',
                    ],
                    [
                        'id'     => 'email',
                        'step'   => 'yaymail_send_email',
                        'config' => [
                            'subject'   => __( 'Thanks for your order, {{ customer.first_name | default: "there" }}', 'yaymail' ),
                            'design_id' => '',
                        ],
                    ],
                ],
            ]
        );

        $module->template(
            'yaymail_review_request',
            [
                'label'            => __( 'Review request designed in YayMail', 'yaymail' ),
                'description'      => __( 'A week after an order is completed, ask for a review of what the customer hasn’t reviewed yet.', 'yaymail' ),
                'library_category' => 'post_purchase',
                'created'          => '2026-11-27',
                'before'           => $before,
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
                        'id'     => 'wait',
                        'step'   => 'wait',
                        'config' => [
                            'delay' => [
                                'value' => 7,
                                'unit'  => 'days',
                            ],
                        ],
                    ],
                    [
                        'id'     => 'check',
                        'step'   => 'condition',
                        'config' => [
                            'match' => 'all',
                            'rules' => [
                                [
                                    'field'    => 'order.has_products_not_reviewed',
                                    'operator' => 'is_true',
                                ],
                            ],
                        ],
                        'then'   => [
                            [
                                'id'     => 'email',
                                'step'   => 'yaymail_send_email',
                                'config' => [
                                    'subject'   => __( 'How did your order go?', 'yaymail' ),
                                    'design_id' => '',
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $module->template(
            'yaymail_abandoned_cart',
            [
                'label'            => __( 'Cart recovery email designed in YayMail', 'yaymail' ),
                'description'      => __( 'Remind shoppers of the cart they left, with an email designed in YayMail.', 'yaymail' ),
                'library_category' => 'recover_carts',
                'created'          => '2026-11-27',
                'before'           => $before,
                'steps'            => [
                    [
                        'id'   => 'trigger',
                        'step' => 'cart_abandoned',
                    ],
                    [
                        'id'     => 'email',
                        'step'   => 'yaymail_send_email',
                        'config' => [
                            'subject'   => __( 'You left something in your cart', 'yaymail' ),
                            'design_id' => '',
                        ],
                    ],
                ],
            ]
        );
    }
}
