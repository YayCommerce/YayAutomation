<?php
namespace YayAutomation\Engine\Data;

defined( 'ABSPATH' ) || exit;

/**
 * The person behind the event. Context object: anything with WooCommerce billing getters — the
 * \WC_Order for order triggers (works for guests too), a \WC_Customer for customer triggers.
 */
final class CustomerData extends DataItem {

    public const ID = 'customer';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Customer', 'yay-automation' );
    }

    public function description(): string {
        return __( 'The person who placed the order, from its billing details. Works for guests too.', 'yay-automation' );
    }

    /** Countries as code => name. Empty without WooCommerce. */
    public static function countries(): array {
        return function_exists( 'WC' ) ? WC()->countries->get_countries() : [];
    }

    protected function define(): array {
        return [
            'email'           => [
                'label' => __( 'Customer email', 'yay-automation' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => $customer->get_billing_email(),
            ],
            'first_name'      => [
                'label' => __( 'Customer first name', 'yay-automation' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => $customer->get_billing_first_name(),
            ],
            'last_name'       => [
                'label' => __( 'Customer last name', 'yay-automation' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => $customer->get_billing_last_name(),
            ],
            'full_name'       => [
                'label' => __( 'Customer full name', 'yay-automation' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() ),
            ],
            'phone'           => [
                'label' => __( 'Customer phone', 'yay-automation' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => $customer->get_billing_phone(),
            ],
            'billing_country' => [
                'label'   => __( 'Billing country', 'yay-automation' ),
                'type'    => self::TYPE_ENUM,
                'value'   => fn( $customer ) => $customer->get_billing_country(),
                'options' => [ self::class, 'countries' ],
            ],
        ];
    }
}
