<?php
namespace YayMatik\Workflow\Core\Data;

use YayMatik\Workflow\Core\Cart\CartCustomer;
use YayMatik\Workflow\Core\Cart\SavedCart;
use YayMatik\Workflow\DataItems\CartData;
use YayMatik\Workflow\DataItems\CustomerData;
use YayMatik\Workflow\DataItems\OrderData;
use YayMatik\Workflow\DataItems\OrderItemData;
use YayMatik\Workflow\DataItems\RefundData;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the entity a ref points at, once per resolver; a new resolver per slice reads fresh data after a wait.
 */
final class RefResolver {

    /** @var callable[] Entity type id => fn( string $id ): ?object */
    private $loaders;

    /** @var array */
    private $loaded = [];

    /**
     * @param callable[]|null $loaders Null = the WooCommerce loaders.
     */
    public function __construct( ?array $loaders = null ) {
        $this->loaders = $loaders ?? self::default_loaders();
    }

    /**
     * @param Ref $ref
     * @return object
     * @throws GoneEntity When it no longer exists or its type has no loader.
     */
    public function load( Ref $ref ) {
        $key = $ref->key();
        if ( ! array_key_exists( $key, $this->loaded ) ) {
            $loader               = $this->loaders[ $ref->type() ] ?? null;
            $this->loaded[ $key ] = $loader ? $loader( $ref->id() ) : null;
        }
        if ( ! is_object( $this->loaded[ $key ] ) ) {
            throw new GoneEntity( $ref );
        }
        return $this->loaded[ $key ];
    }

    /** @return callable[] */
    public static function default_loaders(): array {
        return [
            OrderData::ID     => fn( string $id ) => self::order( $id ),
            CartData::ID      => fn( string $id ) => SavedCart::find( (int) $id ),
            OrderItemData::ID => function ( string $id ) {
                $item = function_exists( 'WC' ) ? \WC_Order_Factory::get_order_item( (int) $id ) : false;
                return $item ? $item : null;
            },
            RefundData::ID    => function ( string $id ) {
                $refund = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $id ) : false;
                return $refund instanceof \WC_Order_Refund ? $refund : null;
            },
            // `order:34` reads the order's billing details (guests too); `cart:5` the cart's shopper; `user:12` an account.
            CustomerData::ID  => function ( string $id ) {
                list( $kind, $number ) = array_pad( explode( ':', $id, 2 ), 2, '' );
                if ( 'order' === $kind ) {
                    return self::order( $number );
                }
                if ( 'cart' === $kind ) {
                    $cart = SavedCart::find( (int) $number );
                    return $cart ? CartCustomer::of( $cart ) : null;
                }
                return 'user' === $kind ? self::account( (int) $number ) : null;
            },
        ];
    }

    /**
     * An account as a customer, null when gone. Billing details are empty until a first checkout, and the customer's data reads those: the account's email and first name fill them in memory, never saved.
     *
     * @param int $id
     */
    public static function account( int $id ): ?\WC_Customer {
        $user = function_exists( 'WC' ) && $id > 0 ? get_userdata( $id ) : false;
        if ( ! $user ) {
            return null;
        }
        $customer = new \WC_Customer( $id );
        // The setter throws on an invalid address: a legacy one shouldn't fail the whole run at its start.
        if ( '' === $customer->get_billing_email( 'edit' ) && is_email( $user->user_email ) ) {
            $customer->set_billing_email( $user->user_email );
        }
        if ( '' === $customer->get_billing_first_name( 'edit' ) ) {
            $customer->set_billing_first_name( $user->first_name );
        }
        return $customer;
    }

    private static function order( string $id ) {
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $id ) : false;
        return $order instanceof \WC_Order ? $order : null;
    }
}
