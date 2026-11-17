<?php
namespace YayMatik\Pro\Workflow\DataItems;

use YayMatik\Workflow\Core\Cart\CartCustomer;
use YayMatik\Workflow\Core\Data\CustomerStats;
use YayMatik\Workflow\Core\Data\MetaKeys;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\DataItems\CustomerData;

defined( 'ABSPATH' ) || exit;

/** The customer in YayMatik Pro: the free fields plus order history, account and custom fields. */
final class CustomerProData extends CustomerData {

    /** Field order in the pickers: Pro's sit beside the free fields they belong with. */
    private const ORDER = [ 'email', 'first_name', 'last_name', 'full_name', 'phone', 'billing_country', 'is_first_order', 'paid_order_count', 'total_spent', 'last_order_date', 'registered_date', 'role', 'is_guest', 'purchased_product_ids', 'meta', 'unsubscribed' ];


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
        parent::__construct( $paid_orders, $unsubscribed );
        $this->stats = $stats ?? [ CustomerStats::class, 'totals' ];
    }

    /** Role slug => name; empty without WordPress. */
    public static function roles(): array {
        return function_exists( 'wp_roles' ) ? array_map( 'translate_user_role', wp_roles()->get_names() ) : [];
    }


    protected function define(): array {
        $fields = parent::define() + [
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
        ];
        return array_merge( array_intersect_key( array_flip( self::ORDER ), $fields ), $fields );
    }

    public function sample(): array {
        return parent::sample() + [
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
            'purchased_product_ids' => [
                'value' => [ '1' ],
                'text'  => __( 'Sample product', 'yaymatik' ),
            ],
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
}
