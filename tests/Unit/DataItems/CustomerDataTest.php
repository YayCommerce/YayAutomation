<?php
namespace YayMatik\Tests\Unit\DataItems;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Cart\CartCustomer;
use YayMatik\Workflow\Core\Cart\SavedCart;
use YayMatik\Workflow\DataItems\CustomerData;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** The customer fields, with the order lookups and stats faked. */
final class CustomerDataTest extends TestCase {

    /** @var array[] Queries the lookup got. */
    private $queries = [];

    /** @var array[] [ user id, email ] per stats call. */
    private $stats_calls = [];

    /** @var mixed */
    private $wpdb;

    protected function setUp(): void {
        $this->wpdb      = $GLOBALS['wpdb'] ?? null;
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here; MetaKeys reads its prefix.
        $GLOBALS['wpdb'] = (object) [ 'base_prefix' => 'wp_' ];
    }

    protected function tearDown(): void {
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- puts back what setUp() replaced.
        $GLOBALS['wpdb'] = $this->wpdb;
        unset( $GLOBALS['yaymatik_test_users'] );
    }

    /**
     * Paid order ids per account and per billing email, as the lookup would find them.
     *
     * @param array $by_user      User id => paid order ids.
     * @param array $by_email     Email => paid order ids.
     * @param array $unsubscribed Unsubscribed emails, lowercased.
     */
    private function item( array $by_user, array $by_email, array $unsubscribed = [] ): CustomerData {
        return new CustomerData(
            function ( array $query ) use ( $by_user, $by_email ) {
                $this->queries[] = $query;
                $ids             = isset( $query['customer_id'] ) ? ( $by_user[ $query['customer_id'] ] ?? [] ) : ( $by_email[ $query['billing_email'] ] ?? [] );
                return array_slice( $ids, 0, $query['limit'] );
            },
            fn( string $email ) => in_array( strtolower( $email ), $unsubscribed, true ),
            function ( int $user_id, string $email ) {
                $this->stats_calls[] = [ $user_id, $email ];
                return [
                    'count'     => 4,
                    'spent'     => 120.5,
                    'last_paid' => 1700000000,
                ];
            }
        );
    }

    private function order( int $id, int $customer_id, string $email ): \WC_Order {
        return new \WC_Order(
            [
                'id'            => $id,
                'customer_id'   => $customer_id,
                'billing_email' => $email,
            ]
        );
    }

    private function first( CustomerData $item, $customer ): bool {
        return ( $item->fields()['is_first_order']['value'] )( $customer );
    }

    public function test_a_guest_with_only_this_paid_order(): void {
        $item = $this->item( [], [ 'ann@x.com' => [ 10 ] ] );

        $this->assertTrue( $this->first( $item, $this->order( 10, 0, 'Ann@X.com ' ) ) );
        $this->assertSame(
            [
                [
                    'billing_email' => 'ann@x.com',
                    'limit'         => 2,
                ],
            ],
            $this->queries,
            'a guest is found by email only, lowercased'
        );
    }

    public function test_a_guest_with_an_earlier_paid_order(): void {
        $item = $this->item( [], [ 'ann@x.com' => [ 10, 7 ] ] );

        $this->assertFalse( $this->first( $item, $this->order( 10, 0, 'ann@x.com' ) ) );
    }

    public function test_a_registered_customer_found_by_account_and_email_once(): void {
        $item = $this->item( [ 5 => [ 10 ] ], [ 'ann@x.com' => [ 10 ] ] );

        $this->assertTrue( $this->first( $item, $this->order( 10, 5, 'ann@x.com' ) ), 'the same order from both lookups counts once' );
        $this->assertSame( [ 5, 'ann@x.com' ], array_map( fn( array $query ) => $query['customer_id'] ?? $query['billing_email'], $this->queries ) );
    }

    public function test_a_registered_customer_whose_earlier_guest_order_used_the_same_email(): void {
        $item = $this->item( [ 5 => [ 10 ] ], [ 'ann@x.com' => [ 10, 3 ] ] );

        $this->assertFalse( $this->first( $item, $this->order( 10, 5, 'ann@x.com' ) ) );
    }

    public function test_an_earlier_order_on_the_account_with_another_email(): void {
        $item = $this->item( [ 5 => [ 10, 4 ] ], [ 'new@x.com' => [ 10 ] ] );

        $this->assertFalse( $this->first( $item, $this->order( 10, 5, 'new@x.com' ) ) );
    }

    public function test_refunded_cancelled_or_failed_orders_do_not_count(): void {
        // The lookup asks for paid statuses only, so those orders never come back.
        $item = $this->item( [], [ 'ann@x.com' => [ 10 ] ] );

        $this->assertTrue( $this->first( $item, $this->order( 10, 0, 'ann@x.com' ) ) );
    }

    public function test_this_orders_own_status_does_not_matter(): void {
        // A pending (or on-hold) order isn't found by the paid lookup; with no other paid order it's still first.
        $none  = $this->item( [], [] );
        $other = $this->item( [], [ 'ann@x.com' => [ 7 ] ] );

        $this->assertTrue( $this->first( $none, $this->order( 10, 0, 'ann@x.com' ) ) );
        $this->assertFalse( $this->first( $other, $this->order( 10, 0, 'ann@x.com' ) ) );
    }

    public function test_no_email_and_no_account(): void {
        $item = $this->item( [], [] );

        $this->assertTrue( $this->first( $item, $this->order( 10, 0, '' ) ), 'nothing to find, so no other paid order' );
        $this->assertSame( [], $this->queries );
    }

    public function test_paid_order_count_comes_from_the_stats_as_a_float(): void {
        $item = $this->item( [], [] );

        $this->assertSame( 4.0, ( $item->fields()['paid_order_count']['value'] )( $this->order( 10, 5, 'ann@x.com' ) ) );
        $this->assertSame( 'Paid order count', $item->fields()['paid_order_count']['label'] );
        $this->assertSame( [ [ 5, 'ann@x.com' ] ], $this->stats_calls );
    }

    public function test_total_spent_and_last_order_date_come_from_the_stats(): void {
        $fields = $this->item( [], [] )->fields();
        $order  = $this->order( 10, 5, 'ann@x.com' );

        $this->assertSame( 120.5, ( $fields['total_spent']['value'] )( $order ) );
        $this->assertSame( 1700000000, ( $fields['last_order_date']['timestamp'] )( $order ) );
        $this->assertSame( 'money', $fields['total_spent']['value_type']->to_string() );
    }

    public function test_stats_are_read_once_per_customer_object(): void {
        $fields = $this->item( [], [] )->fields();
        $order  = $this->order( 10, 5, 'ann@x.com' );

        ( $fields['paid_order_count']['value'] )( $order );
        ( $fields['total_spent']['value'] )( $order );
        $this->assertCount( 1, $this->stats_calls, 'two reads, one call' );

        ( $fields['total_spent']['value'] )( $this->order( 10, 5, 'ann@x.com' ) );
        $this->assertCount( 2, $this->stats_calls, 'a new object reads again, as after a Wait' );
    }

    public function test_stats_know_each_kind_of_customer(): void {
        $fields  = $this->item( [], [] )->fields();
        $account = new \WC_Customer(
            [
                'id'            => 7,
                'billing_email' => 'a@x.com',
            ]
        );
        $cart    = $this->cart_customer( [ 'email' => 'c@x.com' ], null );

        ( $fields['total_spent']['value'] )( $account );
        ( $fields['total_spent']['value'] )( $cart );
        ( $fields['total_spent']['value'] )( $this->order( 10, 0, 'g@x.com' ) );

        $this->assertSame( [ [ 7, 'a@x.com' ], [ 0, 'c@x.com' ], [ 0, 'g@x.com' ] ], $this->stats_calls );
    }

    public function test_guest_and_account_fields(): void {
        $GLOBALS['yaymatik_test_users'] = [
            5 => (object) [
                'roles'           => [ 'customer' ],
                'user_registered' => '2026-01-02 03:04:05',
                'meta'            => [
                    'tier'           => 'gold',
                    'list'           => [ 'a' ],
                    'session_tokens' => 'secret',
                ],
            ],
        ];
        $fields                         = $this->item( [], [] )->fields();
        $user                           = $this->order( 10, 5, 'a@x.com' );
        $guest                          = $this->order( 11, 0, 'g@x.com' );

        $this->assertFalse( ( $fields['is_guest']['value'] )( $user ) );
        $this->assertTrue( ( $fields['is_guest']['value'] )( $guest ) );
        $this->assertSame( [ 'customer' ], ( $fields['role']['value'] )( $user ) );
        $this->assertSame( [], ( $fields['role']['value'] )( $guest ) );
        $this->assertSame( gmmktime( 3, 4, 5, 1, 2, 2026 ), ( $fields['registered_date']['timestamp'] )( $user ) );
        $this->assertNull( ( $fields['registered_date']['timestamp'] )( $guest ) );

        $meta = $fields['meta']['meta'];
        $this->assertSame( 'gold', $meta( $user, 'tier' ) );
        $this->assertSame( '', $meta( $user, 'list' ), 'an array reads as empty' );
        $this->assertSame( '', $meta( $guest, 'tier' ) );
        $this->assertSame( '', $meta( $user, 'session_tokens' ), 'blocked on read too' );
    }

    /**
     * @param array             $row
     * @param \WC_Customer|null $account
     */
    private function cart_customer( array $row, ?\WC_Customer $account ): CartCustomer {
        return new CartCustomer(
            new SavedCart(
                $row + [
                    'email'      => 'ann@x.com',
                    'first_name' => 'Ann',
                ]
            ),
            $account
        );
    }

    public function test_a_cart_is_a_first_order_only_with_no_paid_order(): void {
        $customer = $this->cart_customer( [ 'user_id' => 0 ], null );

        $this->assertTrue( $this->first( $this->item( [], [] ), $customer ), 'no paid order' );
        $this->assertFalse( $this->first( $this->item( [], [ 'ann@x.com' => [ 7 ] ] ), $customer ), 'one paid order' );
        $this->assertFalse( $this->first( $this->item( [], [ 'ann@x.com' => [ 7, 8 ] ] ), $customer ), 'two paid orders' );
    }

    public function test_a_guest_cart_looks_up_by_email_only(): void {
        $item = $this->item( [], [ 'ann@x.com' => [ 7 ] ] );

        $this->first( $item, $this->cart_customer( [ 'user_id' => 0 ], null ) );
        $this->assertSame( [ 'ann@x.com' ], array_map( fn( array $query ) => $query['customer_id'] ?? $query['billing_email'], $this->queries ) );
    }

    public function test_a_gone_account_on_a_cart_looks_up_by_email_only(): void {
        $item = $this->item( [ 5 => [ 7 ] ], [] );

        $this->first( $item, $this->cart_customer( [ 'user_id' => 5 ], null ) );
        $this->assertSame( [ 'ann@x.com' ], array_map( fn( array $query ) => $query['customer_id'] ?? $query['billing_email'], $this->queries ) );
    }

    public function test_a_logged_in_cart_looks_up_by_account_and_the_rows_email(): void {
        $item    = $this->item( [ 5 => [ 7 ] ], [ 'ann@x.com' => [ 7 ] ] );
        $account = new \WC_Customer(
            [
                'id'            => 5,
                'billing_email' => 'old@x.com',
            ]
        );

        $this->assertFalse( $this->first( $item, $this->cart_customer( [ 'user_id' => 5 ], $account ) ) );
        $this->assertSame( [ 5, 'ann@x.com' ], array_map( fn( array $query ) => $query['customer_id'] ?? $query['billing_email'], $this->queries ) );
    }

    public function test_a_cart_customer_reads_email_and_first_name_from_the_row_and_the_rest_from_the_account(): void {
        $account  = new \WC_Customer(
            [
                'billing_email'      => '',
                'billing_first_name' => '',
                'billing_last_name'  => 'Lee',
                'billing_phone'      => '555',
                'billing_country'    => 'VN',
            ]
        );
        $customer = $this->cart_customer( [ 'user_id' => 5 ], $account );
        $fields   = $this->item( [], [] )->fields();

        $this->assertSame( 'ann@x.com', ( $fields['email']['value'] )( $customer ), 'never empty' );
        $this->assertSame( 'Ann', ( $fields['first_name']['value'] )( $customer ) );
        $this->assertSame( 'Lee', ( $fields['last_name']['value'] )( $customer ) );
        $this->assertSame( 'Ann Lee', ( $fields['full_name']['value'] )( $customer ) );
        $this->assertSame( '555', ( $fields['phone']['value'] )( $customer ) );
        $this->assertSame( 'VN', ( $fields['billing_country']['value'] )( $customer ) );
    }

    public function test_a_guest_cart_customer_has_only_email_and_first_name(): void {
        $customer = $this->cart_customer( [ 'user_id' => 0 ], null );

        $this->assertSame( 0, $customer->get_id() );
        $this->assertSame( 'ann@x.com', $customer->get_billing_email() );
        $this->assertSame( '', $customer->get_billing_last_name() );
        $this->assertSame( '', $customer->get_billing_phone() );
        $this->assertSame( '', $customer->get_billing_country() );
    }

    public function test_unsubscribed_reads_the_billing_email(): void {
        $item  = $this->item( [], [], [ 'ann@x.com' ] );
        $value = $item->fields()['unsubscribed']['value'];

        $this->assertTrue( $value( $this->order( 10, 0, 'Ann@x.com' ) ) );
        $this->assertFalse( $value( $this->order( 11, 0, 'bob@x.com' ) ) );
    }
}
