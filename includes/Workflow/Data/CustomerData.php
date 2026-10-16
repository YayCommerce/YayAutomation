<?php
namespace YayMatik\Workflow\Data;

use YayMatik\Workflow\Storage\UnsubscribeStore;

defined( 'ABSPATH' ) || exit;

/**
 * The person behind the event. Context object: anything with WooCommerce billing getters — the
 * \WC_Order for order triggers (works for guests too), a \WC_Customer for customer triggers.
 */
final class CustomerData extends DataItem {

    public const ID = 'customer';

    /** Order statuses that count as paid for `is_first_order`: a paid order besides this one means it isn't. */
    public const PAID_STATUSES = [ 'processing', 'completed' ];

    /** @var callable fn( array $query ): int[] — paid order ids for `customer_id` or `billing_email`, up to 2. */
    private $paid_orders;

    /** @var callable fn( string $email ): bool */
    private $unsubscribed;

    /**
     * @param callable|null $paid_orders  Paid order lookup; null = WooCommerce's.
     * @param callable|null $unsubscribed Unsubscribe lookup; null = `UnsubscribeStore::has()`.
     */
    public function __construct( ?callable $paid_orders = null, ?callable $unsubscribed = null ) {
        $this->paid_orders  = $paid_orders ?? [ self::class, 'find_paid_orders' ];
        $this->unsubscribed = $unsubscribed ?? [ UnsubscribeStore::class, 'has' ];
    }

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
            'is_first_order'  => [
                'label' => __( 'Customer’s first order', 'yaymatik' ),
                'type'  => self::TYPE_BOOLEAN,
                'value' => fn( $customer ) => $this->is_first_order( $customer ),
            ],
            'unsubscribed'    => [
                'label' => __( 'Customer unsubscribed', 'yaymatik' ),
                'type'  => self::TYPE_BOOLEAN,
                'value' => fn( $customer ) => (bool) ( $this->unsubscribed )( (string) $customer->get_billing_email() ),
            ],
        ];
    }

    /**
     * Whether the customer has no paid order (processing or completed) besides this one, whatever
     * this order's status. Orders are found by account and by billing email, so a guest order with
     * the same email counts too. Refunded, cancelled, failed and unpaid orders don't count. Without
     * an order (a customer trigger): whether they have exactly one paid order.
     *
     * @param \WC_Order|\WC_Customer $customer Context object.
     */
    private function is_first_order( $customer ): bool {
        $is_order = $customer instanceof \WC_Order;
        $order_id = $is_order ? (int) $customer->get_id() : 0;
        $user_id  = (int) ( $is_order ? $customer->get_customer_id() : $customer->get_id() );
        $email    = strtolower( trim( (string) $customer->get_billing_email() ) );

        $ids = [];
        if ( $user_id > 0 ) {
            $ids = array_merge( $ids, ( $this->paid_orders )( [ 'customer_id' => $user_id ] ) );
        }
        if ( '' !== $email ) {
            $ids = array_merge( $ids, ( $this->paid_orders )( [ 'billing_email' => $email ] ) );
        }
        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
        if ( ! $is_order ) {
            return 1 === count( $ids );
        }
        return ! array_diff( $ids, [ $order_id ] );
    }

    /**
     * Up to 2 paid order ids: enough to tell whether there's one besides this order.
     *
     * @param array $query `customer_id` or `billing_email`.
     * @return int[]
     */
    public static function find_paid_orders( array $query ): array {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return [];
        }
        $ids = wc_get_orders(
            $query + [
                'type'   => 'shop_order',
                'status' => self::PAID_STATUSES,
                'limit'  => 2,
                'return' => 'ids',
            ]
        );
        return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
    }
}
