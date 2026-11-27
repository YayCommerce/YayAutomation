<?php
/**
 * YayLoyalty's YayMatik extension: declarations only; the adapter does the work.
 *
 * @package YayLoyalty
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- another plugin: its own text domain.

/** Registers YayLoyalty's module on `yaymatik_register`. */
final class YayLoyalty_YayMatik {

    /** @var YayLoyalty_YayMatik_Fake_Adapter */
    private $adapter;

    /**
     * @param YayLoyalty_YayMatik_Fake_Adapter $adapter
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
                'id'           => 'yayloyalty',
                'label'        => 'YayLoyalty',
                'description'  => __( 'Reward points and tiers.', 'yayloyalty' ),
                'logo'         => plugins_url( 'logo.svg', __FILE__ ),
                'requires_api' => '1.1',
            ]
        );
        if ( ! $module->ok() ) {
            return;
        }

        $module->data_fields(
            'customer',
            [
                'yayloyalty_points' => [
                    'label'  => __( 'Points balance', 'yayloyalty' ),
                    'type'   => 'number',
                    'value'  => [ $this->adapter, 'customer_points' ],
                    'sample' => 340,
                ],
                'yayloyalty_tier'   => [
                    'label'   => __( 'Loyalty tier', 'yayloyalty' ),
                    'type'    => 'enum',
                    'options' => [ $this->adapter, 'tier_options' ],
                    'value'   => [ $this->adapter, 'customer_tier' ],
                    'sample'  => 'silver',
                ],
            ]
        );

        $module->trigger(
            'yayloyalty_points_earned',
            [
                'label'        => __( 'Customer earned points', 'yayloyalty' ),
                'description'  => __( 'Starts when a customer gets reward points.', 'yayloyalty' ),
                'category'     => 'customer',
                'icon'         => 'coins',
                'provides'     => [ 'customer' ],
                'facts'        => [
                    'points' => [
                        'label' => __( 'Points earned', 'yayloyalty' ),
                        'type'  => 'number',
                    ],
                ],
                'sample_facts' => [ 'points' => 25 ],
            ]
        );
        $module->trigger(
            'yayloyalty_tier_reached',
            [
                'label'        => __( 'Customer reached a tier', 'yayloyalty' ),
                'description'  => __( 'Starts when a customer moves up to a loyalty tier.', 'yayloyalty' ),
                'category'     => 'customer',
                'icon'         => 'star',
                'provides'     => [ 'customer' ],
                'facts'        => [
                    'tier' => [
                        'label'   => __( 'Tier reached', 'yayloyalty' ),
                        'type'    => 'enum',
                        'options' => [ $this->adapter, 'tier_options' ],
                    ],
                ],
                'sample_facts' => [ 'tier' => 'silver' ],
            ]
        );
        $module->trigger(
            'yayloyalty_points_expiring',
            [
                'label'        => __( 'Points expiring soon', 'yayloyalty' ),
                'description'  => __( 'Starts when some of a customer’s points will expire soon.', 'yayloyalty' ),
                'category'     => 'customer',
                'icon'         => 'hourglass',
                'provides'     => [ 'customer' ],
                'facts'        => [
                    'points'    => [
                        'label' => __( 'Points expiring', 'yayloyalty' ),
                        'type'  => 'number',
                    ],
                    'days_left' => [
                        'label' => __( 'Days left', 'yayloyalty' ),
                        'type'  => 'number',
                    ],
                ],
                'sample_facts' => [
                    'points'    => 150,
                    'days_left' => 7,
                ],
            ]
        );

        $module->action(
            'yayloyalty_award_points',
            [
                'label'       => __( 'Award points', 'yayloyalty' ),
                'description' => __( 'Gives the customer reward points.', 'yayloyalty' ),
                'category'    => 'customer',
                'icon'        => 'coins',
                'needs'       => [ 'customer' ],
                'settings'    => [
                    'points' => [
                        'type'     => 'number',
                        'label'    => __( 'Points', 'yayloyalty' ),
                        'min'      => 1,
                        'required' => true,
                    ],
                ],
                /* translators: {{ config.points }}: a number; keep {{ … }} as it is. */
                'summary'     => __( 'Award {{ config.points }} points', 'yayloyalty' ),
                'outputs'     => [
                    'points_added' => [
                        'label' => __( 'Points added', 'yayloyalty' ),
                        'type'  => 'number',
                    ],
                    'balance'      => [
                        'label' => __( 'New balance', 'yayloyalty' ),
                        'type'  => 'number',
                    ],
                ],
                /* translators: {{ output.points_added }}, {{ output.balance }}: numbers; keep {{ … }} as it is. */
                'result'      => __( 'Added {{ output.points_added }} points; balance {{ output.balance }}', 'yayloyalty' ),
                'handle'      => [ $this->adapter, 'award_points' ],
            ]
        );

        $module->template(
            'yayloyalty_points_balance',
            [
                'label'            => __( 'Points balance email', 'yayloyalty' ),
                'description'      => __( 'When customers earn points, tell them what they earned and their new balance.', 'yayloyalty' ),
                'library_category' => 'customer_lifecycle',
                'created'          => '2026-11-27',
                'steps'            => [
                    [
                        'id'   => 'trigger',
                        'step' => 'yayloyalty_points_earned',
                    ],
                    [
                        'id'     => 'email',
                        'step'   => 'send_email',
                        'config' => [
                            'to'      => '{{ customer.email }}',
                            'subject' => __( 'You earned {{ trigger.points }} points', 'yayloyalty' ),
                            'heading' => __( 'More points for you', 'yayloyalty' ),
                            'message' => __( '<p>Hi {{ customer.first_name | default: "there" }},</p><p>You just earned {{ trigger.points }} points. Your balance is now {{ customer.yayloyalty_points }} points.</p><p>{{ site.shop_button }}</p>', 'yayloyalty' ),
                        ],
                    ],
                ],
            ]
        );

        $module->template(
            'yayloyalty_tier_coupon',
            [
                'label'            => __( 'Tier reward coupon', 'yayloyalty' ),
                'description'      => __( 'When customers reach a loyalty tier, celebrate with a coupon only they can use.', 'yayloyalty' ),
                'library_category' => 'customer_lifecycle',
                'created'          => '2026-11-27',
                'steps'            => [
                    [
                        'id'   => 'trigger',
                        'step' => 'yayloyalty_tier_reached',
                    ],
                    [
                        'id'     => 'coupon',
                        'step'   => 'generate_coupon',
                        'config' => [
                            'discount_type'     => 'percent',
                            'amount'            => 15,
                            'expiry_days'       => 30,
                            'limit_to_customer' => true,
                            'code_prefix'       => 'TIER',
                        ],
                    ],
                    [
                        'id'     => 'email',
                        'step'   => 'send_email',
                        'config' => [
                            'to'      => '{{ customer.email }}',
                            'subject' => __( 'Welcome to {{ trigger.tier }}: a gift inside', 'yayloyalty' ),
                            'heading' => __( 'You reached a new tier', 'yayloyalty' ),
                            'message' => __( '<p>Hi {{ customer.first_name | default: "there" }},</p><p>You reached {{ trigger.tier }}. To celebrate, here’s a coupon just for you:</p><p>{{ steps.coupon.box }}</p>', 'yayloyalty' ),
                        ],
                    ],
                ],
            ]
        );

        $module->template(
            'yayloyalty_points_expiring',
            [
                'label'            => __( 'Points expiring reminder', 'yayloyalty' ),
                'description'      => __( 'Remind customers to use their points before they expire.', 'yayloyalty' ),
                'library_category' => 'customer_lifecycle',
                'created'          => '2026-11-27',
                'steps'            => [
                    [
                        'id'   => 'trigger',
                        'step' => 'yayloyalty_points_expiring',
                    ],
                    [
                        'id'     => 'email',
                        'step'   => 'send_email',
                        'config' => [
                            'to'      => '{{ customer.email }}',
                            'subject' => __( '{{ trigger.points }} of your points expire in {{ trigger.days_left }} days', 'yayloyalty' ),
                            'heading' => __( 'Use them before they’re gone', 'yayloyalty' ),
                            'message' => __( '<p>Hi {{ customer.first_name | default: "there" }},</p><p>{{ trigger.points }} of your points expire in {{ trigger.days_left }} days. Spend them on your next order.</p><p>{{ site.shop_button }}</p>', 'yayloyalty' ),
                        ],
                    ],
                ],
            ]
        );

        $module->template(
            'yayloyalty_points_waiting',
            [
                'label'            => __( 'Points waiting win-back', 'yayloyalty' ),
                'description'      => __( 'Invite back customers who haven’t ordered in 90 days and still have points.', 'yayloyalty' ),
                'library_category' => 'customer_lifecycle',
                'created'          => '2026-11-27',
                'steps'            => [
                    [
                        'id'     => 'trigger',
                        'step'   => 'customer_inactive',
                        'config' => [ 'days' => 90 ],
                    ],
                    [
                        'id'     => 'check',
                        'step'   => 'condition',
                        'config' => [
                            'match' => 'all',
                            'rules' => [
                                [
                                    'field'    => 'customer.yayloyalty_points',
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
                                    'subject' => __( 'Your {{ customer.yayloyalty_points }} points are waiting', 'yayloyalty' ),
                                    'heading' => __( 'We kept your points', 'yayloyalty' ),
                                    'message' => __( '<p>Hi {{ customer.first_name | default: "there" }},</p><p>You still have {{ customer.yayloyalty_points }} points. Come back and spend them on your next order.</p><p>{{ site.shop_button }}</p>', 'yayloyalty' ),
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }
}
