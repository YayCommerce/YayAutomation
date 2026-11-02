<?php
namespace YayMatik\Tests\Unit\Core\Cart;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Cart\CartSweep;

/** Reaching checkout decides which trigger a cart starts. */
final class CartSweepTest extends TestCase {

    public function test_a_cart_that_never_reached_checkout_is_a_cart_abandon(): void {
        $this->assertSame( 'cart_abandoned', CartSweep::kind( [ 'checkout_at' => null ] ) );
    }

    public function test_a_cart_that_reached_checkout_is_a_checkout_abandon(): void {
        $this->assertSame( 'checkout_abandoned', CartSweep::kind( [ 'checkout_at' => '2026-01-01 10:00:00' ] ) );
    }
}
