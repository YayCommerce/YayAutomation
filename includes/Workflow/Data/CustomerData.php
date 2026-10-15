<?php
namespace YayMatik\Workflow\Data;

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
        return __( 'Customer', 'yaymatik' );
    }

    public function description(): string {
        return __( 'The person who placed the order, from its billing details. Works for guests too.', 'yaymatik' );
    }

    public function icon(): string {
        return 'user';
    }

    /** Countries as code => name. Empty without WooCommerce. */
    public static function countries(): array {
        return function_exists( 'WC' ) ? WC()->countries->get_countries() : [];
    }

    protected function define(): array {
        return [
            'email'           => [
                'label' => __( 'Customer email', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => $customer->get_billing_email(),
            ],
            'first_name'      => [
                'label' => __( 'Customer first name', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => $customer->get_billing_first_name(),
            ],
            'last_name'       => [
                'label' => __( 'Customer last name', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => $customer->get_billing_last_name(),
            ],
            'full_name'       => [
                'label' => __( 'Customer full name', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() ),
            ],
            'phone'           => [
                'label' => __( 'Customer phone', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => $customer->get_billing_phone(),
            ],
            'billing_country' => [
                'label'   => __( 'Billing country', 'yaymatik' ),
                'type'    => self::TYPE_ENUM,
                'value'   => fn( $customer ) => $customer->get_billing_country(),
                'options' => [ self::class, 'countries' ],
            ],
        ];
    }
}
