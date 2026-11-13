<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Api\CartView;
use YayMatik\Workflow\Core\Cart\SavedCart;

defined( 'ABSPATH' ) || exit;

/**
 * A `SavedCart` as the public `CartView`.
 *
 * @internal
 */
final class CartViewAdapter implements CartView {

    private const LINE_KEYS = [ 'product_id', 'variation_id', 'quantity', 'line_total' ];

    /** @var SavedCart */
    private $cart;

    /**
     * @param SavedCart $cart
     */
    public function __construct( SavedCart $cart ) {
        $this->cart = $cart;
    }

    public function id(): int {
        return $this->cart->id();
    }

    public function email(): string {
        return $this->cart->email();
    }

    public function user_id(): int {
        return $this->cart->user_id();
    }

    public function currency(): string {
        return $this->cart->currency();
    }

    public function total(): float {
        return $this->cart->total();
    }

    public function items(): array {
        return array_map( fn( array $line ) => array_intersect_key( $line, array_flip( self::LINE_KEYS ) ), $this->cart->lines() );
    }
}
