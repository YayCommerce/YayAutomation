<?php
namespace YayMatik\Tests\Unit\Pro\Workflow\Triggers;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeOrder;
use YayMatik\Workflow\Core\Storage\TriggerClaimStore;
use YayMatik\Pro\Workflow\Triggers\CustomerMilestone;
use YayMatik\Pro\Workflow\Triggers\CustomerOrderCountReaches;
use YayMatik\Pro\Workflow\Triggers\CustomerTotalSpendReaches;

require_once dirname( __DIR__, 4 ) . '/Support/woocommerce-stubs.php';

/** The milestones: they start on the paid order that crosses the number, once per person. */
final class CustomerMilestoneTest extends TestCase {

    /** @var array What the fake stats return. */
    private $totals = [];

    /** @var array[] Calls to the fake stats: [ user id, email ]. */
    private $asked = [];

    protected function setUp(): void {
        $this->order( 12, 7, 'Jane@Example.com', 40.0, 0.0 );
    }

    protected function tearDown(): void {
        FakeOrder::$found = [];
    }

    private function order( int $id, int $user_id, string $email, float $total, float $refunded ): void {
        FakeOrder::$found[ $id ] = new FakeOrder(
            [
                'id'             => $id,
                'type'           => 'shop_order',
                'status'         => 'processing',
                'customer_id'    => $user_id,
                'billing_email'  => $email,
                'total'          => (string) $total,
                'total_refunded' => (string) $refunded,
            ]
        );
    }

    /**
     * @param string $class_name
     */
    private function trigger( string $class_name ): CustomerMilestone {
        return new $class_name(
            function ( int $user_id, string $email ) {
                $this->asked[] = [ $user_id, $email ];
                return $this->totals;
            }
        );
    }

    /**
     * Whether a workflow with this config starts on order 12, given the person's totals after it.
     *
     * @param string $class_name
     * @param array  $config
     * @param int    $count
     * @param float  $spent
     */
    private function starts( string $class_name, array $config, int $count, float $spent ): bool {
        $this->totals = [
            'count'     => $count,
            'spent'     => $spent,
            'last_paid' => null,
        ];
        $trigger      = $this->trigger( $class_name );
        return $trigger->matches( $trigger->context( [ 'order_id' => 12 ] ), $trigger->sanitize( $config ) );
    }

    /**
     * @return array[] [ paid orders after this one, target, starts ]
     */
    public static function counts(): array {
        return [
            'reaches it'       => [ 2, 2, true ],
            'one short'        => [ 1, 2, false ],
            'already past'     => [ 3, 2, false ],
            'first order of 1' => [ 1, 1, true ],
        ];
    }

    /**
     * @dataProvider counts
     *
     * @param int  $after
     * @param int  $target
     * @param bool $starts
     */
    public function test_order_count_crossing( int $after, int $target, bool $starts ): void {
        $this->assertSame( $starts, $this->starts( CustomerOrderCountReaches::class, [ 'count' => $target ], $after, 0.0 ) );
    }

    /**
     * @return array[] [ spent after this order, target, this order's refunds, starts ]
     */
    public static function amounts(): array {
        return [
            'crosses it'              => [ 110.0, 100.0, 0.0, true ],
            'lands on it'             => [ 100.0, 100.0, 0.0, true ],
            'already past'            => [ 150.0, 100.0, 0.0, false ],
            'short'                   => [ 90.0, 100.0, 0.0, false ],
            // This order is 40 with 15 refunded: it adds 25, so 95 → 120 crosses 100.
            'a refund inside crosses' => [ 120.0, 100.0, 15.0, true ],
            'a refund keeps it short' => [ 99.99, 100.0, 15.0, false ],
        ];
    }

    /**
     * @dataProvider amounts
     *
     * @param float $after
     * @param float $target
     * @param float $refunded
     * @param bool  $starts
     */
    public function test_total_spend_crossing( float $after, float $target, float $refunded, bool $starts ): void {
        $this->order( 12, 7, 'jane@example.com', 40.0, $refunded );
        $this->assertSame( $starts, $this->starts( CustomerTotalSpendReaches::class, [ 'amount' => $target ], 1, $after ) );
    }

    public function test_order_count_saves_a_whole_number(): void {
        $trigger = $this->trigger( CustomerOrderCountReaches::class );

        $this->assertSame( [ 'count' => 3 ], $trigger->sanitize( [ 'count' => 2.6 ] ) );
        $this->assertSame( [ 'count' => 5 ], $trigger->sanitize( [] ), 'the default' );
        $this->assertNotTrue( $trigger->validate( [ 'count' => 0 ] ) );
        $this->assertNotTrue( $this->trigger( CustomerTotalSpendReaches::class )->validate( $this->trigger( CustomerTotalSpendReaches::class )->sanitize( [] ) ), 'no amount yet' );
    }

    public function test_claim_key_is_the_account_or_a_hash_of_the_billing_email(): void {
        $trigger = $this->trigger( CustomerOrderCountReaches::class );
        $this->order( 13, 0, 'Jane@Example.com', 40.0, 0.0 );
        $this->order( 14, 0, 'jane@example.com ', 40.0, 0.0 );
        $guest = $trigger->claim_key( [ 'order_id' => 13 ] );

        $this->assertSame( 'user:7', $trigger->claim_key( [ 'order_id' => 12 ] ) );
        $this->assertMatchesRegularExpression( '/^email:[0-9a-f]{64}$/', $guest );
        $this->assertSame( 70, strlen( $guest ) );
        $this->assertSame( $guest, $trigger->claim_key( [ 'order_id' => 14 ] ), 'any case' );
        $this->assertSame( $guest, TriggerClaimStore::email_key( 'JANE@example.com' ) );
        $this->assertStringNotContainsString( 'example', $guest );
    }

    public function test_the_run_keeps_the_key_and_is_about_the_order(): void {
        $trigger = $this->trigger( CustomerOrderCountReaches::class );
        $refs    = $trigger->refs( [ 'order_id' => 12 ] );

        $this->assertSame( 'order:12', $trigger->subject( [ 'order_id' => 12 ] ) );
        $this->assertSame( 'user:7', $refs['claim_key'] );
        $this->assertTrue( $trigger->claims_per_workflow() );
        $this->assertSame( 'customer', $trigger->category() );
    }

    public function test_no_person_no_run(): void {
        $this->order( 15, 0, '', 40.0, 0.0 );
        $trigger = $this->trigger( CustomerOrderCountReaches::class );

        $this->assertNull( $trigger->context( [ 'order_id' => 15 ] ) );
        $this->assertNull( $trigger->refs( [ 'order_id' => 15 ] ) );
        $this->assertSame( [], $this->asked, 'no stats query' );
    }

    public function test_stats_are_read_once_per_event_for_the_account_and_email(): void {
        $this->starts( CustomerOrderCountReaches::class, [ 'count' => 2 ], 2, 0.0 );

        $this->assertSame( [ [ 7, 'Jane@Example.com' ] ], $this->asked );
    }
}
