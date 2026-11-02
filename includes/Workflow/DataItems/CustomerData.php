<?php
namespace YayMatik\Workflow\DataItems;

use YayMatik\Workflow\Core\Cart\CartCustomer;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Storage\UnsubscribeStore;

defined( 'ABSPATH' ) || exit;

/**
 * The person behind the event: anything with WooCommerce billing getters (a \WC_Order or `CartCustomer`, so guests work too).
 */
final class CustomerData extends DataItem {

    public const ID = 'customer';

    /** Order statuses that count as paid for `is_first_order` and `paid_order_count`. */
    public const PAID_STATUSES = [ 'processing', 'completed' ];

    /** @var callable fn( array $query ): int[] */
    private $paid_orders;

    /** @var callable fn( string $email ): bool */
    private $unsubscribed;

    /**
     * @param callable|null $paid_orders  fn( array $query ): int[]; null = `find_paid_orders()`.
     * @param callable|null $unsubscribed fn( string $email ): bool; null = `UnsubscribeStore::has()`.
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
        return __( 'The person behind the event, from their billing details. Works for guests too.', 'yaymatik' );
    }

    public function icon(): string {
        return 'user';
    }

    /** Code => name; empty without WooCommerce. */
    public static function countries(): array {
        return function_exists( 'WC' ) ? WC()->countries->get_countries() : [];
    }

    protected function define(): array {
        return [
            'email'            => [
                'label' => __( 'Customer email', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => $customer->get_billing_email(),
            ],
            'first_name'       => [
                'label' => __( 'Customer first name', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => $customer->get_billing_first_name(),
            ],
            'last_name'        => [
                'label' => __( 'Customer last name', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => $customer->get_billing_last_name(),
            ],
            'full_name'        => [
                'label' => __( 'Customer full name', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() ),
            ],
            'phone'            => [
                'label' => __( 'Customer phone', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => $customer->get_billing_phone(),
            ],
            'billing_country'  => [
                'label'   => __( 'Billing country', 'yaymatik' ),
                'type'    => self::TYPE_ENUM,
                'value'   => fn( $customer ) => $customer->get_billing_country(),
                'options' => [ self::class, 'countries' ],
            ],
            'is_first_order'   => [
                'label' => __( 'Customer’s first order', 'yaymatik' ),
                'type'  => self::TYPE_BOOLEAN,
                'value' => fn( $customer ) => $this->is_first_order( $customer ),
            ],
            'paid_order_count' => [
                'label' => __( 'Customer paid order count', 'yaymatik' ),
                'type'  => self::TYPE_NUMBER,
                'value' => fn( $customer ) => (float) count( $this->paid_ids( $customer, -1 ) ),
            ],
            'unsubscribed'     => [
                'label' => __( 'Customer unsubscribed', 'yaymatik' ),
                'type'  => self::TYPE_BOOLEAN,
                'value' => fn( $customer ) => (bool) ( $this->unsubscribed )( (string) $customer->get_billing_email() ),
            ],
        ];
    }

    /** @return array Field key => value, or [ 'value', 'text' ] when it reads differently. */
    public function sample(): array {
        return [
            'email'            => 'jane.doe@example.com',
            'first_name'       => 'Jane',
            'last_name'        => 'Doe',
            'full_name'        => 'Jane Doe',
            'phone'            => '555-0100',
            'billing_country'  => [
                'value' => 'US',
                'text'  => self::countries()['US'] ?? 'United States (US)',
            ],
            'is_first_order'   => true,
            'paid_order_count' => 1.0,
            'unsubscribed'     => false,
        ];
    }

    /**
     * Whether the customer has no paid order besides this one, whatever this order's status.
     *
     * @param \WC_Order|\WC_Customer|CartCustomer $customer
     */
    private function is_first_order( $customer ): bool {
        // 2 are enough to tell whether there's one besides this order.
        $ids = $this->paid_ids( $customer, 2 );
        if ( ! $customer instanceof \WC_Order ) {
            // A customer trigger: exactly one paid order.
            return 1 === count( $ids );
        }
        return ! array_diff( $ids, [ (int) $customer->get_id() ] );
    }

    /**
     * The customer's paid order ids, by account and by billing email, so a guest order with the same email counts too.
     *
     * @param \WC_Order|\WC_Customer|CartCustomer $customer
     * @param int                                 $limit    Per lookup; -1 = all.
     * @return int[]
     */
    private function paid_ids( $customer, int $limit ): array {
        $is_order = $customer instanceof \WC_Order;
        $user_id  = (int) ( $is_order ? $customer->get_customer_id() : $customer->get_id() );
        $email    = strtolower( trim( (string) $customer->get_billing_email() ) );
        $ids      = [];
        if ( $user_id > 0 ) {
            $ids = array_merge(
                $ids,
                ( $this->paid_orders )(
                    [
                        'customer_id' => $user_id,
                        'limit'       => $limit,
                    ]
                )
            );
        }
        if ( '' !== $email ) {
            $ids = array_merge(
                $ids,
                ( $this->paid_orders )(
                    [
                        'billing_email' => $email,
                        'limit'         => $limit,
                    ]
                )
            );
        }
        return array_values( array_unique( array_map( 'intval', $ids ) ) );
    }

    /**
     * Paid order ids.
     *
     * @param array $query `customer_id` or `billing_email`, and `limit`.
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
                'return' => 'ids',
            ]
        );
        return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
    }
}
