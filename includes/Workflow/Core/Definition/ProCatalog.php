<?php
namespace YayMatik\Workflow\Core\Definition;

use YayMatik\Workflow\Conditions\Criteria;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Steps\StepRegistry;

defined( 'ABSPATH' ) || exit;

/** What YayMatik Pro adds, by name only: no behaviour lives here. */
final class ProCatalog {

    /** @var array[] `missing()` by registered steps and data items. */
    private static $missing = [];

    /** The plugin name a step or rule that needs it shows. */
    public const PLUGIN = 'YayMatik Pro';

    /** Step id => kind. */
    public const STEPS = [
        'customer_inactive'            => 'trigger',
        'customer_order_count_reaches' => 'trigger',
        'customer_total_spend_reaches' => 'trigger',
        'user_logged_in'               => 'trigger',
        'customer_logged_in'           => 'trigger',
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
        'customer.last_added_to_cart',
        'customer.cart_item_count',
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

    /** Condition features, as `Criteria::features()` names them: groups, comparing with another field. */
    public const FEATURES = [ 'groups', 'variables' ];

    /**
     * A Pro step as a workflow that keeps it draws it: `module`, `plugin`, `kind`, `label`, `outputs` (`key`, `label`), `facts`.
     *
     * @param string $id
     */
    public static function step( string $id ): ?array {
        if ( ! isset( self::STEPS[ $id ] ) ) {
            return null;
        }
        $names                   = [
            'customer_inactive'            => [ __( 'Customer inactive', 'yaymatik-automation-for-woocommerce' ), [] ],
            'customer_order_count_reaches' => [ __( 'Customer order count reaches', 'yaymatik-automation-for-woocommerce' ), [] ],
            'customer_total_spend_reaches' => [ __( 'Customer total spend reaches', 'yaymatik-automation-for-woocommerce' ), [] ],
            'user_logged_in'               => [ __( 'User logged in', 'yaymatik-automation-for-woocommerce' ), [] ],
            'customer_logged_in'           => [ __( 'Customer logged in', 'yaymatik-automation-for-woocommerce' ), [] ],
            'wait_until'                   => [ __( 'Wait until', 'yaymatik-automation-for-woocommerce' ), [ 'until' => __( 'Waited until', 'yaymatik-automation-for-woocommerce' ) ] ],
            'resend_wc_email'              => [ __( 'Resend WooCommerce email', 'yaymatik-automation-for-woocommerce' ), [ 'email' => __( 'Email', 'yaymatik-automation-for-woocommerce' ) ] ],
            'update_order_meta'            => [
                __( 'Update order custom field', 'yaymatik-automation-for-woocommerce' ),
                [
                    'key'     => __( 'Field name', 'yaymatik-automation-for-woocommerce' ),
                    'changed' => __( 'Value changed', 'yaymatik-automation-for-woocommerce' ),
                ],
            ],
            'update_customer_meta'         => [
                __( 'Update customer custom field', 'yaymatik-automation-for-woocommerce' ),
                [
                    'key'     => __( 'Field name', 'yaymatik-automation-for-woocommerce' ),
                    'changed' => __( 'Value changed', 'yaymatik-automation-for-woocommerce' ),
                ],
            ],
            'change_customer_role'         => [
                __( 'Change customer role', 'yaymatik-automation-for-woocommerce' ),
                [
                    'from' => __( 'Roles before', 'yaymatik-automation-for-woocommerce' ),
                    'to'   => __( 'Roles after', 'yaymatik-automation-for-woocommerce' ),
                ],
            ],
        ];
        list( $label, $outputs ) = $names[ $id ];
        return [
            'module'  => 'wait_until' === $id ? 'yaymatik' : 'woocommerce',
            'plugin'  => self::PLUGIN,
            'kind'    => self::STEPS[ $id ],
            'label'   => $label,
            'outputs' => array_map(
                fn( string $key, string $output ) => [
                    'key'   => $key,
                    'label' => $output,
                ],
                array_keys( $outputs ),
                $outputs
            ),
            'facts'   => [],
        ];
    }

    /**
     * The catalog minus what is registered now: empty when YayMatik Pro is active.
     *
     * @return array[] `steps` (ids), `fields` (keys), `features`.
     */
    public static function missing(): array {
        // Read for every step of every workflow loaded: once per set of registered steps and data items.
        $steps = StepRegistry::all();
        $key   = implode( ',', array_keys( $steps ) ) . '|' . implode( ',', array_keys( DataRegistry::items() ) );
        if ( isset( self::$missing[ $key ] ) ) {
            return self::$missing[ $key ];
        }
        $condition             = $steps[ Criteria::ID ] ?? null;
        self::$missing[ $key ] = [
            'steps'    => array_keys( array_diff_key( self::STEPS, $steps ) ),
            'fields'   => array_values( array_filter( self::FIELDS, fn( string $field ) => null === DataRegistry::field( $field ) ) ),
            'features' => array_values( array_diff( self::FEATURES, $condition instanceof Criteria ? $condition->features() : [] ) ),
        ];
        return self::$missing[ $key ];
    }
}
