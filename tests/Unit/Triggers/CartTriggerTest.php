<?php
namespace YayMatik\Tests\Unit\Triggers;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeWpdb;
use YayMatik\Workflow\Triggers\CartAbandoned;
use YayMatik\Workflow\Triggers\CheckoutAbandoned;

/** Cart triggers: each listens to its own kind of abandon and starts a cart's one series. */
final class CartTriggerTest extends TestCase {

    /** @var FakeWpdb */
    private $db;

    protected function setUp(): void {
        $this->db = new FakeWpdb();
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
        $GLOBALS['wpdb'] = $this->db;
    }

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_filters']['yaymatik_cart_abandoned'] );
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_each_trigger_emits_only_its_own_kind(): void {
        $emitted = [];
        ( new CartAbandoned() )->listen(
            function ( array $args ) use ( &$emitted ) {
                $emitted[] = [ 'cart', $args['cart_id'] ];
            }
        );
        ( new CheckoutAbandoned() )->listen(
            function ( array $args ) use ( &$emitted ) {
                $emitted[] = [ 'checkout', $args['cart_id'] ];
            }
        );

        do_action( 'yaymatik_cart_abandoned', 4, 'checkout_abandoned' );
        do_action( 'yaymatik_cart_abandoned', 5, 'cart_abandoned' );

        $this->assertSame( [ [ 'checkout', 4 ], [ 'cart', 5 ] ], $emitted );
    }

    public function test_claim_starts_the_series_only_on_a_cart_without_one(): void {
        $row               = [
            'id'     => '7',
            'email'  => 'ann@x.test',
            'status' => 'abandoned',
        ];
        $this->db->results = [ $row, null, null, 1 ];
        $this->assertTrue( ( new CheckoutAbandoned() )->claim( [ 'cart_id' => 7 ] ) );
        $this->assertMatchesRegularExpression( "/^UPDATE `wp_yaymatik_carts` SET series_at = '[0-9: -]+', abandoned_at = '[0-9: -]+', abandon_trigger = 'checkout_abandoned' WHERE id = 7 AND series_at IS NULL AND status <> 'ordered'$/", $this->db->last_sql() );

        // Another series or an order came first: the UPDATE changes nothing.
        $this->db->results = [ $row, null, null, 0 ];
        $this->assertFalse( ( new CartAbandoned() )->claim( [ 'cart_id' => 7 ] ) );
    }
}
