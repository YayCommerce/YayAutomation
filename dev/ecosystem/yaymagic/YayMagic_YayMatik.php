<?php
/**
 * YayMagic's YayMatik extension: declarations only; the adapter does the work.
 *
 * @package YayMagic
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- another plugin: its own text domain.

/** Registers YayMagic's module on `yaymatik_register`. */
final class YayMagic_YayMatik {

    /** @var YayMagic_YayMatik_Fake_Adapter */
    private $adapter;

    /**
     * @param YayMagic_YayMatik_Fake_Adapter $adapter
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
                'id'           => 'yaymagic',
                'label'        => 'YayMagic',
                'description'  => __( 'Emails designed in YayMagic, with or without an order.', 'yaymagic' ),
                'logo'         => plugins_url( 'logo.svg', __FILE__ ),
                'requires_api' => '1.0',
            ]
        );
        if ( ! $module->ok() ) {
            return;
        }

        $module->action(
            'yaymagic_send_email',
            [
                'label'       => __( 'Send email designed in YayMagic', 'yaymagic' ),
                'description' => __( 'Sends the customer an email designed in YayMagic.', 'yaymagic' ),
                'category'    => 'email',
                'settings'    => [
                    'subject'   => [
                        'type'      => 'text',
                        'label'     => __( 'Subject', 'yaymagic' ),
                        'variables' => true,
                        'required'  => true,
                    ],
                    'design_id' => [
                        'type'     => 'select',
                        'label'    => __( 'Design', 'yaymagic' ),
                        'options'  => [ $this->adapter, 'design_options' ],
                        'required' => true,
                    ],
                ],
                /* translators: {{ config.subject }}: the email's subject; keep {{ … }} as it is. */
                'summary'     => __( 'Email “{{ config.subject }}”', 'yaymagic' ),
                'email'       => [
                    'audience'  => 'customer',
                    'recipient' => 'customer',
                    'build'     => [ $this->adapter, 'build_email' ],
                ],
            ]
        );

        $before = [ __( 'Pick the email’s design in YayMagic after you add the template.', 'yaymagic' ) ];

        $module->template(
            'yaymagic_welcome',
            [
                'label'            => __( 'Welcome email designed in YayMagic', 'yaymagic' ),
                'description'      => __( 'Welcome new customers when they create an account, with an email designed in YayMagic.', 'yaymagic' ),
                'library_category' => 'customer_lifecycle',
                'created'          => '2026-11-27',
                'before'           => $before,
                'steps'            => [
                    [
                        'id'   => 'trigger',
                        'step' => 'customer_account_created',
                    ],
                    [
                        'id'     => 'email',
                        'step'   => 'yaymagic_send_email',
                        'config' => [
                            'subject'   => __( 'Welcome to {{ site.name }}, {{ customer.first_name | default: "friend" }}', 'yaymagic' ),
                            'design_id' => '',
                        ],
                    ],
                ],
            ]
        );

        $module->template(
            'yaymagic_win_back',
            [
                'label'            => __( 'Win-back email designed in YayMagic', 'yaymagic' ),
                'description'      => __( 'Invite back customers who haven’t ordered in 90 days, with an email designed in YayMagic.', 'yaymagic' ),
                'library_category' => 'customer_lifecycle',
                'created'          => '2026-11-27',
                'before'           => $before,
                'steps'            => [
                    [
                        'id'     => 'trigger',
                        'step'   => 'customer_inactive',
                        'config' => [ 'days' => 90 ],
                    ],
                    [
                        'id'     => 'email',
                        'step'   => 'yaymagic_send_email',
                        'config' => [
                            'subject'   => __( 'We miss you, {{ customer.first_name | default: "friend" }}', 'yaymagic' ),
                            'design_id' => '',
                        ],
                    ],
                ],
            ]
        );

        $module->template(
            'yaymagic_milestone',
            [
                'label'            => __( 'Milestone thank-you designed in YayMagic', 'yaymagic' ),
                'description'      => __( 'Thank customers on their 5th paid order, with an email designed in YayMagic.', 'yaymagic' ),
                'library_category' => 'customer_lifecycle',
                'created'          => '2026-11-27',
                'before'           => $before,
                'steps'            => [
                    [
                        'id'     => 'trigger',
                        'step'   => 'customer_order_count_reaches',
                        'config' => [ 'count' => 5 ],
                    ],
                    [
                        'id'     => 'email',
                        'step'   => 'yaymagic_send_email',
                        'config' => [
                            'subject'   => __( 'Five orders, {{ customer.first_name | default: "friend" }}: thank you', 'yaymagic' ),
                            'design_id' => '',
                        ],
                    ],
                ],
            ]
        );
    }
}
