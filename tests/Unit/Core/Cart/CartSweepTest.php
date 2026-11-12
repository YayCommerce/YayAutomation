<?php
namespace YayMatik\Tests\Unit\Core\Cart;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeWpdb;
use YayMatik\Workflow\Core\Cart\CartSweep;
use YayMatik\Workflow\Core\Storage\Settings;

/** Reaching checkout decides which trigger a cart starts; only a cart without a series fires. */
final class CartSweepTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_options'], $GLOBALS['yaymatik_test_filters']['yaymatik_cart_abandoned'] );
        $this->forget_settings();
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_a_cart_that_never_reached_checkout_is_a_cart_abandon(): void {
        $this->assertSame( 'cart_abandoned', CartSweep::kind( [ 'checkout_at' => null ] ) );
    }

    public function test_a_cart_that_reached_checkout_is_a_checkout_abandon(): void {
        $this->assertSame( 'checkout_abandoned', CartSweep::kind( [ 'checkout_at' => '2026-01-01 10:00:00' ] ) );
    }

    public function test_each_abandon_of_a_cart_without_a_series_fires_with_its_kind(): void {
        $GLOBALS['yaymatik_test_options']['yaymatik_settings'] = [ 'cart_tracking' => true ];
        $this->forget_settings();
        $db          = new FakeWpdb();
        $db->results = [
            [
                [
                    'id'               => '1',
                    'last_activity_at' => '2026-01-01 10:00:00',
                    'checkout_at'      => null,
                    'series_at'        => null,
                ],
                [
                    'id'               => '2',
                    'last_activity_at' => '2026-01-01 10:00:00',
                    'checkout_at'      => '2026-01-01 09:59:00',
                    'series_at'        => null,
                ],
                [
                    'id'               => '3',
                    'last_activity_at' => '2026-01-01 10:00:00',
                    'checkout_at'      => '2026-01-01 09:59:00',
                    'series_at'        => '2026-01-01 08:00:00',
                ],
            ],
        ];
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
        $GLOBALS['wpdb'] = $db;
        $fired           = [];
        add_action(
            'yaymatik_cart_abandoned',
            function ( $id, $kind ) use ( &$fired ) {
                $fired[] = [ $id, $kind ];
            }
        );

        CartSweep::run();

        $this->assertSame( [ [ 1, 'cart_abandoned' ], [ 2, 'checkout_abandoned' ] ], $fired );
        $marked = array_values( array_filter( $db->calls, fn( array $call ) => 'update' === $call[0] ) );
        $this->assertCount( 3, $marked );
        $this->assertSame( [ 'status' => 'abandoned' ], $marked[2][2] );
    }

    private function forget_settings(): void {
        $built = new \ReflectionProperty( Settings::class, 'all' );
        $built->setAccessible( true );
        $built->setValue( null, null );
    }
}
