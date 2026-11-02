<?php
namespace YayMatik\Workflow\Triggers;

use YayMatik\Workflow\Core\Cart\CartCustomer;
use YayMatik\Workflow\Core\Cart\SavedCart;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Runtime\Cancellation;
use YayMatik\Workflow\Core\Steps\Trigger;
use YayMatik\Workflow\Core\Storage\CartStore;
use YayMatik\Workflow\Core\Storage\UnsubscribeStore;
use YayMatik\Workflow\DataItems\CartData;
use YayMatik\Workflow\DataItems\CustomerData;

defined( 'ABSPATH' ) || exit;

/** Base for a cart's first abandon in its cycle. Event args hold `cart_id`. */
abstract class CartTrigger extends Trigger {

    /** One reminder series per email in this time. */
    private const SERIES_EVERY = 7 * DAY_IN_SECONDS;

    public function category(): string {
        return 'cart';
    }

    public function provides(): array {
        return [ CartData::ID, CustomerData::ID ];
    }

    public function fields(): array {
        return [];
    }

    public function subject( array $args ): string {
        return 'cart:' . (int) ( $args['cart_id'] ?? 0 );
    }

    public function listen( callable $emit ): void {
        add_action(
            'yaymatik_cart_abandoned',
            function ( $cart_id ) use ( $emit ) {
                $row = CartStore::find( (int) $cart_id );
                if ( $row && $this->id() === $row['abandon_trigger'] ) {
                    $emit( [ 'cart_id' => (int) $cart_id ] );
                }
            }
        );
    }

    /**
     * @param array $args
     */
    public function context( array $args ): ?array {
        $cart = SavedCart::find( (int) ( $args['cart_id'] ?? 0 ) );
        return $cart ? [
            CartData::ID     => $cart,
            CustomerData::ID => CartCustomer::of( $cart ),
        ] : null;
    }

    /**
     * @param array $args
     */
    public function refs( array $args ): ?array {
        $cart = SavedCart::find( (int) ( $args['cart_id'] ?? 0 ) );
        if ( ! $cart ) {
            return null;
        }
        return [
            CartData::ID     => new Ref( CartData::ID, $cart->id() ),
            CustomerData::ID => new Ref( CustomerData::ID, CartData::ID . ':' . $cart->id() ),
        ];
    }

    /**
     * Skips ordered carts, unsubscribed shoppers and emails with a series running; else marks this cart's series as started.
     *
     * @param array $args
     */
    public function claim( array $args ): bool {
        $id  = (int) ( $args['cart_id'] ?? 0 );
        $row = CartStore::find( $id );
        // Events are claimed in order, so the older cart of a sweep starts and blocks the next.
        if ( ! $row || CartStore::ORDERED === $row['status'] || UnsubscribeStore::has( $row['email'] ) || CartStore::series_taken( $row['email'], $id, gmdate( 'Y-m-d H:i:s', time() - self::SERIES_EVERY ) ) ) {
            return false;
        }
        CartStore::mark_series( $id );
        return true;
    }

    /**
     * Only a gone or ordered cart stops a run: a shopper who returns and leaves again stays in the series.
     *
     * @param string $subject
     */
    public function stop_reason( string $subject ): ?string {
        $row = CartStore::find( (int) substr( $subject, strlen( 'cart:' ) ) );
        if ( ! $row ) {
            return Cancellation::cart_gone();
        }
        return CartStore::ORDERED === $row['status'] ? Cancellation::order_placed( (int) $row['order_id'] ) : null;
    }
}
