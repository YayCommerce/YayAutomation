<?php
namespace YayMatik\Workflow\DataItems;

use YayMatik\Workflow\Core\Cart\CartCustomer;
use YayMatik\Workflow\Core\Data\CustomerStats;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\MetaKeys;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Storage\UnsubscribeStore;

defined( 'ABSPATH' ) || exit;

/**
 * The person behind the event: anything with WooCommerce billing getters (a \WC_Order or `CartCustomer`, so guests work too).
 */
final class CustomerData extends DataItem {

    public const ID = 'customer';

    /** @var callable fn( array $query ): int[] */
    private $paid_orders;

    /** @var callable fn( string $email ): bool */
    private $unsubscribed;

    /** @var callable fn( int $user_id, string $email ): array, as `CustomerStats::totals()`. */
    private $stats;

    /** @var object|null The customer `$memo_stats` belongs to. A new object per slice, so a Wait reads fresh numbers. */
    private $memo_customer = null;

    /** @var array */
    private $memo_stats = [];

    /**
     * @param callable|null $paid_orders  fn( array $query ): int[]; null = `find_paid_orders()`.
     * @param callable|null $unsubscribed fn( string $email ): bool; null = `UnsubscribeStore::has()`.
     * @param callable|null $stats        fn( int $user_id, string $email ): array; null = `CustomerStats::totals()`.
     */
    public function __construct( ?callable $paid_orders = null, ?callable $unsubscribed = null, ?callable $stats = null ) {
        $this->paid_orders  = $paid_orders ?? [ self::class, 'find_paid_orders' ];
        $this->unsubscribed = $unsubscribed ?? [ UnsubscribeStore::class, 'has' ];
        $this->stats        = $stats ?? [ CustomerStats::class, 'totals' ];
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

    /** Role slug => name; empty without WordPress. */
    public static function roles(): array {
        return function_exists( 'wp_roles' ) ? array_map( 'translate_user_role', wp_roles()->get_names() ) : [];
    }

    /**
     * The account behind any customer object; 0 for a guest.
     *
     * @param \WC_Order|\WC_Customer|CartCustomer $customer
     */
    public static function user_id( $customer ): int {
        return (int) ( $customer instanceof \WC_Order ? $customer->get_customer_id() : $customer->get_id() );
    }

    protected function define(): array {
        return [
            'email'                 => [
                'label' => __( 'Customer email', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => $customer->get_billing_email(),
            ],
            'first_name'            => [
                'label' => __( 'Customer first name', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => $customer->get_billing_first_name(),
            ],
            'last_name'             => [
                'label' => __( 'Customer last name', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => $customer->get_billing_last_name(),
            ],
            'full_name'             => [
                'label' => __( 'Customer full name', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() ),
            ],
            'phone'                 => [
                'label' => __( 'Customer phone', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( $customer ) => $customer->get_billing_phone(),
            ],
            'billing_country'       => [
                'label'   => __( 'Billing country', 'yaymatik' ),
                'type'    => self::TYPE_ENUM,
                'value'   => fn( $customer ) => $customer->get_billing_country(),
                'options' => [ self::class, 'countries' ],
            ],
            'is_first_order'        => [
                'label' => __( 'Customer’s first order', 'yaymatik' ),
                'type'  => self::TYPE_BOOLEAN,
                'value' => fn( $customer ) => $this->is_first_order( $customer ),
            ],
            'paid_order_count'      => [
                'label' => __( 'Paid order count', 'yaymatik' ),
                'type'  => self::TYPE_NUMBER,
                'value' => fn( $customer ) => (float) $this->stats_of( $customer )['count'],
            ],
            'total_spent'           => [
                'label'      => __( 'Total spent', 'yaymatik' ),
                'help'       => __( 'Orders in other currencies are added as stored, not converted.', 'yaymatik' ),
                'type'       => self::TYPE_NUMBER,
                'value_type' => Type::money(),
                'value'      => fn( $customer ) => (float) $this->stats_of( $customer )['spent'],
                'display'    => fn( $customer, bool $html ) => self::money_text( (float) $this->stats_of( $customer )['spent'], $html ),
            ],
            'last_order_date'       => [
                'label'     => __( 'Last order date', 'yaymatik' ),
                'help'      => __( 'The newest paid order; pending and failed orders don’t count.', 'yaymatik' ),
                'type'      => self::TYPE_DATE,
                'value'     => fn( $customer ) => self::day( $this->stats_of( $customer )['last_paid'] ),
                'timestamp' => fn( $customer ) => $this->stats_of( $customer )['last_paid'],
                'display'   => fn( $customer, bool $html ) => self::date_text( $this->stats_of( $customer )['last_paid'], $html ),
            ],
            'registered_date'       => [
                'label'     => __( 'Registered date', 'yaymatik' ),
                'type'      => self::TYPE_DATE,
                'value'     => fn( $customer ) => self::day( self::registered_at( $customer ) ),
                'timestamp' => [ self::class, 'registered_at' ],
                'display'   => fn( $customer, bool $html ) => self::date_text( self::registered_at( $customer ), $html ),
            ],
            'role'                  => [
                'label'   => __( 'Role', 'yaymatik' ),
                'type'    => self::TYPE_LIST,
                'value'   => [ self::class, 'roles_of' ],
                'display' => fn( $customer, bool $html ) => self::text(
                    implode( ', ', array_map( fn( string $role ) => self::roles()[ $role ] ?? $role, self::roles_of( $customer ) ) ),
                    $html
                ),
                'options' => [ self::class, 'roles' ],
            ],
            'is_guest'              => [
                'label' => __( 'Is guest', 'yaymatik' ),
                'type'  => self::TYPE_BOOLEAN,
                'value' => fn( $customer ) => 0 === self::user_id( $customer ),
            ],
            'purchased_product_ids' => [
                'label'   => __( 'Products bought', 'yaymatik' ),
                'type'    => self::TYPE_LIST,
                'search'  => 'product',
                'value'   => [ self::class, 'purchased' ],
                'display' => fn( $customer, bool $html ) => self::text( implode( ', ', array_filter( array_map( fn( string $id ) => (string) get_post_field( 'post_title', (int) $id, 'raw' ), self::purchased( $customer ) ) ) ), $html ),
            ],
            'meta'                  => [
                'label'        => __( 'Custom field', 'yaymatik' ),
                'type'         => self::TYPE_META,
                'value'        => fn() => '',
                'merge_tag'    => false,
                'meta'         => [ self::class, 'custom_field' ],
                'meta_blocked' => [ MetaKeys::class, 'user_blocked' ],
            ],
            'unsubscribed'          => [
                'label' => __( 'Customer unsubscribed', 'yaymatik' ),
                'type'  => self::TYPE_BOOLEAN,
                'value' => fn( $customer ) => (bool) ( $this->unsubscribed )( (string) $customer->get_billing_email() ),
            ],
        ];
    }

    /** @return array Field key => value, or [ 'value', 'text' ] when it reads differently. */
    public function sample(): array {
        return [
            'email'                 => 'jane.doe@example.com',
            'first_name'            => 'Jane',
            'last_name'             => 'Doe',
            'full_name'             => 'Jane Doe',
            'phone'                 => '555-0100',
            'billing_country'       => [
                'value' => 'US',
                'text'  => self::countries()['US'] ?? 'United States (US)',
            ],
            'is_first_order'        => true,
            'paid_order_count'      => 1.0,
            'total_spent'           => [
                'value' => 54.0,
                'text'  => html_entity_decode( wp_strip_all_tags( wc_price( 54.0 ) ), ENT_QUOTES, 'UTF-8' ),
                'html'  => wc_price( 54.0 ),
            ],
            'last_order_date'       => [
                'value' => wp_date( 'Y-m-d' ),
                'text'  => wp_date( (string) get_option( 'date_format', 'F j, Y' ) ),
            ],
            'registered_date'       => [
                'value' => wp_date( 'Y-m-d' ),
                'text'  => wp_date( (string) get_option( 'date_format', 'F j, Y' ) ),
            ],
            'role'                  => [
                'value' => [ 'customer' ],
                'text'  => self::roles()['customer'] ?? __( 'Customer', 'yaymatik' ),
            ],
            'is_guest'              => false,
            'purchased_product_ids' => [
                'value' => [ '1' ],
                'text'  => __( 'Sample product', 'yaymatik' ),
            ],
            'unsubscribed'          => false,
        ];
    }

    /**
     * The person's totals, read once per customer object: most rules and tags read several of them.
     *
     * @param \WC_Order|\WC_Customer|CartCustomer $customer
     */
    private function stats_of( $customer ): array {
        if ( $this->memo_customer !== $customer ) {
            $this->memo_stats    = ( $this->stats )( self::user_id( $customer ), (string) $customer->get_billing_email() );
            $this->memo_customer = $customer;
        }
        return $this->memo_stats;
    }

    /**
     * @param \WC_Order|\WC_Customer|CartCustomer $customer
     * @return string[]
     */
    public static function roles_of( $customer ): array {
        $user = get_userdata( self::user_id( $customer ) );
        return $user ? array_values( $user->roles ) : [];
    }

    /**
     * Unix time the account was made; null for a guest.
     *
     * @param \WC_Order|\WC_Customer|CartCustomer $customer
     */
    public static function registered_at( $customer ): ?int {
        $user = get_userdata( self::user_id( $customer ) );
        return $user ? (int) strtotime( $user->user_registered . ' UTC' ) : null;
    }

    /**
     * Read only when a rule or tag asks: it scans the person's order items.
     *
     * @param \WC_Order|\WC_Customer|CartCustomer $customer
     * @return string[]
     */
    public static function purchased( $customer ): array {
        return CustomerStats::product_ids( self::user_id( $customer ), (string) $customer->get_billing_email() );
    }

    /**
     * A custom field as text; '' for a guest, a blocked key, or an array or object value.
     *
     * @param \WC_Order|\WC_Customer|CartCustomer $customer
     * @param string                              $key
     */
    public static function custom_field( $customer, string $key ): string {
        $user_id = self::user_id( $customer );
        if ( ! $user_id || MetaKeys::user_blocked( $key ) ) {
            return '';
        }
        $value = get_user_meta( $user_id, $key, true );
        return is_scalar( $value ) ? (string) $value : '';
    }

    private static function day( ?int $at ): string {
        return null === $at ? '' : wp_date( 'Y-m-d', $at );
    }

    private static function date_text( ?int $at, bool $html ): string {
        return self::text( null === $at ? '' : wp_date( (string) get_option( 'date_format', 'F j, Y' ), $at ), $html );
    }

    private static function money_text( float $amount, bool $html ): string {
        $price = wc_price( $amount );
        return $html ? $price : html_entity_decode( wp_strip_all_tags( $price ), ENT_QUOTES, 'UTF-8' );
    }

    /**
     * An order: no paid order besides this one, whatever its status. A cart: no paid order yet.
     *
     * @param \WC_Order|\WC_Customer|CartCustomer $customer
     */
    private function is_first_order( $customer ): bool {
        $ids = $this->paid_ids( $customer );
        if ( $customer instanceof CartCustomer ) {
            return ! $ids;
        }
        if ( ! $customer instanceof \WC_Order ) {
            // A customer trigger: exactly one paid order.
            return 1 === count( $ids );
        }
        return ! array_diff( $ids, [ (int) $customer->get_id() ] );
    }

    /**
     * Up to 2 of the customer's paid order ids per lookup, by account and by billing email, so a guest order with the same email counts too; enough to tell whether there's one besides this order.
     *
     * @param \WC_Order|\WC_Customer|CartCustomer $customer
     * @return int[]
     */
    private function paid_ids( $customer ): array {
        $user_id = self::user_id( $customer );
        $email   = strtolower( trim( (string) $customer->get_billing_email() ) );
        $ids     = [];
        if ( $user_id > 0 ) {
            $ids = array_merge(
                $ids,
                ( $this->paid_orders )(
                    [
                        'customer_id' => $user_id,
                        'limit'       => 2,
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
                        'limit'         => 2,
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
                'status' => wc_get_is_paid_statuses(),
                'return' => 'ids',
            ]
        );
        return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
    }
}
