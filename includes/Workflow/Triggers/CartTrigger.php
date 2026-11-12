<?php
namespace YayMatik\Workflow\Triggers;

use YayMatik\Workflow\Core\Cart\CartCustomer;
use YayMatik\Workflow\Core\Cart\SavedCart;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Runtime\Cancellation;
use YayMatik\Workflow\Core\Steps\Trigger;
use YayMatik\Workflow\Core\Storage\CartStore;
use YayMatik\Workflow\Core\Storage\Settings;
use YayMatik\Workflow\Core\Storage\UnsubscribeStore;
use YayMatik\Workflow\DataItems\CartData;
use YayMatik\Workflow\DataItems\CustomerData;

defined( 'ABSPATH' ) || exit;

/** Base for a cart abandon: starts the cart's one reminder series, or is skipped. Event args hold `cart_id`. */
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
            function ( $cart_id, $kind ) use ( $emit ) {
                if ( $this->id() === $kind ) {
                    $emit( [ 'cart_id' => (int) $cart_id ] );
                }
            },
            10,
            2
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
     * Starts this cart's series unless `skip_reason()` refuses it or another series or an order came first.
     *
     * @param array $args
     */
    public function claim( array $args ): bool {
        return null === $this->skip_reason( $args ) && CartStore::start_series( (int) ( $args['cart_id'] ?? 0 ), $this->id() );
    }

    /**
     * A cart already in a series, an ordered cart, an unsubscribed shopper, or an email with a series in the last 7 days; null for a gone cart.
     *
     * @param array $args
     */
    public function skip_reason( array $args ): ?string {
        $id  = (int) ( $args['cart_id'] ?? 0 );
        $row = CartStore::find( $id );
        if ( ! $row ) {
            return null;
        }
        if ( ! empty( $row['series_at'] ) ) {
            return __( 'This cart already got a reminder series.', 'yaymatik' );
        }
        if ( CartStore::ORDERED === $row['status'] ) {
            return Cancellation::order_placed( (int) $row['order_id'] );
        }
        if ( UnsubscribeStore::has( $row['email'] ) ) {
            return Cancellation::unsubscribed();
        }
        // Events are claimed in order, so the older cart of a sweep starts and blocks the next.
        if ( CartStore::series_taken( $row['email'], $id, gmdate( 'Y-m-d H:i:s', time() - self::SERIES_EVERY ) ) ) {
            return __( 'This shopper already got a reminder series in the last 7 days.', 'yaymatik' );
        }
        return null;
    }

    public function setup_issue(): ?string {
        if ( Settings::get( 'cart_tracking' ) ) {
            return null;
        }
        /* translators: %s: trigger name */
        return sprintf( __( 'Turn on cart tracking before activating. %s never starts while it’s off.', 'yaymatik' ), $this->label() );
    }

    /**
     * A gone or ordered cart or an unsubscribed shopper stops a run: a shopper who returns and leaves again stays in the series.
     *
     * @param string $subject
     */
    public function stop_reason( string $subject ): ?string {
        $row = CartStore::find( (int) substr( $subject, strlen( 'cart:' ) ) );
        // `[]`: emptied, the row kept only so paying its order can find it.
        if ( ! $row || '[]' === $row['items'] ) {
            return Cancellation::cart_gone();
        }
        if ( CartStore::ORDERED === $row['status'] ) {
            return Cancellation::order_placed( (int) $row['order_id'] );
        }
        return UnsubscribeStore::has( $row['email'] ) ? Cancellation::unsubscribed() : null;
    }
}
