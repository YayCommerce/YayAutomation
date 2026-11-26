<?php
namespace YayMatik\Workflow\Triggers;

defined( 'ABSPATH' ) || exit;

/** A cart that reached checkout was left without an order. */
class CheckoutAbandoned extends CartTrigger {

    public const ID = 'checkout_abandoned';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Checkout abandoned', 'yaymatik-automation-for-woocommerce' );
    }

    public function description(): string {
        return __( 'A shopper leaves the checkout page without ordering', 'yaymatik-automation-for-woocommerce' );
    }

    public function icon(): string {
        return 'credit-card';
    }

    public function summary(): string {
        return __( 'When a checkout is abandoned', 'yaymatik-automation-for-woocommerce' );
    }
}
