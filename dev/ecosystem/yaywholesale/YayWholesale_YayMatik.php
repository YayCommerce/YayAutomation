<?php
/**
 * YayWholesale's YayMatik extension: declarations only; the adapter does the work.
 *
 * @package YayWholesale
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- another plugin: its own text domain.

/** Registers YayWholesale's module on `yaymatik_register`. */
final class YayWholesale_YayMatik {

    /** @var YayWholesale_YayMatik_Fake_Adapter */
    private $adapter;

    /**
     * @param YayWholesale_YayMatik_Fake_Adapter $adapter
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
                'id'           => 'yaywholesale',
                'label'        => 'YayWholesale',
                'description'  => __( 'Wholesale roles, requests and minimums.', 'yaywholesale' ),
                'logo'         => plugins_url( 'logo.svg', __FILE__ ),
                'requires_api' => '1.0',
            ]
        );
        if ( ! $module->ok() ) {
            return;
        }

        $module->data_fields(
            'customer',
            [
                'yaywholesale_role'   => [
                    'label'   => __( 'Wholesale role', 'yaywholesale' ),
                    'type'    => 'enum',
                    'options' => [ $this->adapter, 'role_options' ],
                    'value'   => [ $this->adapter, 'customer_role' ],
                    'sample'  => 'wholesale_gold',
                ],
                'yaywholesale_status' => [
                    'label'   => __( 'Wholesale status', 'yaywholesale' ),
                    'type'    => 'enum',
                    'options' => [ $this->adapter, 'status_options' ],
                    'value'   => [ $this->adapter, 'customer_status' ],
                    'sample'  => 'approved',
                ],
            ]
        );
        $module->data_fields(
            'cart',
            [
                'yaywholesale_minimum'        => [
                    'label'  => __( 'Wholesale minimum', 'yaywholesale' ),
                    'type'   => 'money',
                    'value'  => [ $this->adapter, 'cart_minimum' ],
                    'sample' => 500,
                ],
                'yaywholesale_missing_amount' => [
                    'label'  => __( 'Amount missing for wholesale prices', 'yaywholesale' ),
                    'type'   => 'money',
                    'value'  => [ $this->adapter, 'cart_missing_amount' ],
                    'sample' => 120,
                ],
            ]
        );

        $module->trigger(
            'yaywholesale_request_submitted',
            [
                'label'       => __( 'Wholesale request submitted', 'yaywholesale' ),
                'description' => __( 'Starts when a customer asks to become a wholesaler.', 'yaywholesale' ),
                'category'    => 'customer',
                'icon'        => 'user-plus',
                'provides'    => [ 'customer' ],
            ]
        );
        $module->trigger(
            'yaywholesale_approved',
            [
                'label'        => __( 'Wholesale request approved', 'yaywholesale' ),
                'description'  => __( 'Starts when you approve a customer as a wholesaler.', 'yaywholesale' ),
                'category'     => 'customer',
                'icon'         => 'circle-check',
                'provides'     => [ 'customer' ],
                'facts'        => [
                    'role' => [
                        'label'   => __( 'Approved role', 'yaywholesale' ),
                        'type'    => 'enum',
                        'options' => [ $this->adapter, 'role_options' ],
                    ],
                ],
                'sample_facts' => [ 'role' => 'wholesale_gold' ],
            ]
        );
        $module->trigger(
            'yaywholesale_rejected',
            [
                'label'       => __( 'Wholesale request rejected', 'yaywholesale' ),
                'description' => __( 'Starts when you reject a customer’s wholesale request.', 'yaywholesale' ),
                'category'    => 'customer',
                'icon'        => 'user',
                'provides'    => [ 'customer' ],
            ]
        );

        $module->action(
            'yaywholesale_set_role',
            [
                'label'       => __( 'Set wholesale role', 'yaywholesale' ),
                'description' => __( 'Moves the customer to another wholesale role.', 'yaywholesale' ),
                'category'    => 'customer',
                'icon'        => 'users',
                'needs'       => [ 'customer' ],
                'settings'    => [
                    'role' => [
                        'type'     => 'select',
                        'label'    => __( 'Role', 'yaywholesale' ),
                        'options'  => [ $this->adapter, 'role_options' ],
                        'required' => true,
                    ],
                ],
                /* translators: {{ config.role }}: a wholesale role; keep {{ … }} as it is. */
                'summary'     => __( 'Set role to {{ config.role }}', 'yaywholesale' ),
                'outputs'     => [
                    'role' => [
                        'label'   => __( 'New role', 'yaywholesale' ),
                        'type'    => 'enum',
                        'options' => [ $this->adapter, 'role_options' ],
                    ],
                ],
                /* translators: {{ output.role }}: a wholesale role; keep {{ … }} as it is. */
                'result'      => __( 'Role is now {{ output.role }}', 'yaywholesale' ),
                'handle'      => [ $this->adapter, 'set_role' ],
            ]
        );

        $module->template(
            'yaywholesale_request_alert',
            [
                'label'            => __( 'New wholesale request alert', 'yaywholesale' ),
                'description'      => __( 'Tell the store team when a customer asks to become a wholesaler, so the request gets an answer fast.', 'yaywholesale' ),
                'library_category' => 'alerts',
                'created'          => '2026-11-27',
                'steps'            => [
                    [
                        'id'   => 'trigger',
                        'step' => 'yaywholesale_request_submitted',
                    ],
                    [
                        'id'     => 'alert',
                        'step'   => 'send_internal_email',
                        'config' => [
                            'recipients' => '',
                            'subject'    => __( 'New wholesale request from {{ customer.full_name }}', 'yaywholesale' ),
                            'message'    => __( '<p>{{ customer.full_name }} ({{ customer.email }}) asked to become a wholesaler on {{ site.name }}.</p><p>Approve or reject the request in YayWholesale.</p>', 'yaywholesale' ),
                        ],
                    ],
                ],
            ]
        );

        $module->template(
            'yaywholesale_approved_welcome',
            [
                'label'            => __( 'Wholesale welcome', 'yaywholesale' ),
                'description'      => __( 'Welcome customers you approve as wholesalers, with their wholesale terms.', 'yaywholesale' ),
                'library_category' => 'customer_lifecycle',
                'created'          => '2026-11-27',
                'before'           => [ __( 'Write your wholesale terms into the email.', 'yaywholesale' ) ],
                'steps'            => [
                    [
                        'id'   => 'trigger',
                        'step' => 'yaywholesale_approved',
                    ],
                    [
                        'id'     => 'email',
                        'step'   => 'send_email',
                        'config' => [
                            'to'      => '{{ customer.email }}',
                            'subject' => __( 'Welcome to wholesale at {{ site.name }}', 'yaywholesale' ),
                            'heading' => __( 'You’re approved', 'yaywholesale' ),
                            'message' => __( '<p>Hi {{ customer.first_name | default: "there" }},</p><p>Your wholesale account is ready. Your role: {{ trigger.role }}.</p><p>Our wholesale terms: minimum order, payment terms and shipping.</p><p>{{ site.shop_button }}</p>', 'yaywholesale' ),
                        ],
                    ],
                ],
            ]
        );

        $module->template(
            'yaywholesale_rejected_reply',
            [
                'label'            => __( 'Wholesale request reply', 'yaywholesale' ),
                'description'      => __( 'Answer customers whose wholesale request you reject, politely and at once.', 'yaywholesale' ),
                'library_category' => 'customer_lifecycle',
                'created'          => '2026-11-27',
                'steps'            => [
                    [
                        'id'   => 'trigger',
                        'step' => 'yaywholesale_rejected',
                    ],
                    [
                        'id'     => 'email',
                        'step'   => 'send_email',
                        'config' => [
                            'to'      => '{{ customer.email }}',
                            'subject' => __( 'About your wholesale request', 'yaywholesale' ),
                            'heading' => __( 'Thanks for your interest', 'yaywholesale' ),
                            'message' => __( '<p>Hi {{ customer.first_name | default: "there" }},</p><p>Thank you for asking to become a wholesaler. We can’t offer you a wholesale account right now, but you’re always welcome to shop at our regular prices.</p><p>{{ site.shop_button }}</p>', 'yaywholesale' ),
                        ],
                    ],
                ],
            ]
        );

        $module->template(
            'yaywholesale_cart_minimum',
            [
                'label'            => __( 'Wholesale minimum reminder', 'yaywholesale' ),
                'description'      => __( 'When a wholesaler leaves a cart below the wholesale minimum, tell them how much more unlocks wholesale prices.', 'yaywholesale' ),
                'library_category' => 'recover_carts',
                'created'          => '2026-11-27',
                'steps'            => [
                    [
                        'id'   => 'trigger',
                        'step' => 'cart_abandoned',
                    ],
                    [
                        'id'     => 'check',
                        'step'   => 'condition',
                        'config' => [
                            'match' => 'all',
                            'rules' => [
                                [
                                    'field'    => 'customer.yaywholesale_status',
                                    'operator' => 'is',
                                    'value'    => 'approved',
                                ],
                                [
                                    'field'    => 'cart.yaywholesale_missing_amount',
                                    'operator' => 'greater_than',
                                    'value'    => '0',
                                ],
                            ],
                        ],
                        'then'   => [
                            [
                                'id'     => 'email',
                                'step'   => 'send_email',
                                'config' => [
                                    'to'      => '{{ customer.email }}',
                                    'subject' => __( 'Add {{ cart.yaywholesale_missing_amount }} more for wholesale prices', 'yaywholesale' ),
                                    'heading' => __( 'You’re almost there', 'yaywholesale' ),
                                    'message' => __( '<p>Hi {{ customer.first_name | default: "there" }},</p><p>Your cart is {{ cart.yaywholesale_missing_amount }} below the wholesale minimum of {{ cart.yaywholesale_minimum }}. Add a little more and wholesale prices apply.</p><p>{{ cart.restore_button }}</p>', 'yaywholesale' ),
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }
}
