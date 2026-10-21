<?php
namespace YayMatik\Workflow\Core\Data;

use YayMatik\Workflow\DataItems\CustomerData;
use YayMatik\Workflow\DataItems\OrderData;
use YayMatik\Workflow\DataItems\OrderItemData;

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
    private static function default_loaders(): array {
        return [
            OrderData::ID     => fn( string $id ) => self::order( $id ),
            OrderItemData::ID => function ( string $id ) {
                $item = function_exists( 'WC' ) ? \WC_Order_Factory::get_order_item( (int) $id ) : false;
                return $item ? $item : null;
            },
            // `order:34` reads the order's billing details (guests too); `user:12` an account.
            CustomerData::ID  => function ( string $id ) {
                list( $kind, $number ) = array_pad( explode( ':', $id, 2 ), 2, '' );
                if ( 'order' === $kind ) {
                    return self::order( $number );
                }
                if ( 'user' === $kind && (int) $number > 0 && function_exists( 'WC' ) && get_userdata( (int) $number ) ) {
                    return new \WC_Customer( (int) $number );
                }
                return null;
            },
        ];
    }

    /**
     * @param string $id
     */
    private static function order( string $id ) {
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $id ) : false;
        return $order instanceof \WC_Order ? $order : null;
    }
}
