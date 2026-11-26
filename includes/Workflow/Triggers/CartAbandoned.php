<?php
namespace YayMatik\Workflow\Triggers;

defined( 'ABSPATH' ) || exit;

/** A cart that never reached checkout was left without an order. */
class CartAbandoned extends CartTrigger {

    public const ID = 'cart_abandoned';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Cart abandoned', 'yaymatik-automation-for-woocommerce' );
    }

    public function description(): string {
        return __( 'A shopper leaves products in the cart', 'yaymatik-automation-for-woocommerce' );
    }

    public function icon(): string {
        return 'shopping-cart';
    }

    public function summary(): string {
        return __( 'When a cart is abandoned', 'yaymatik-automation-for-woocommerce' );
    }
}
