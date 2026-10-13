<?php
namespace YayAutomation\Workflow\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the entity a ref points at, on demand, and keeps it for the rest of the slice: a new
 * resolver per slice means data is read fresh after a wait. Loaders are per entity type.
 */
final class RefResolver {

    /** @var callable[] Entity type id => fn( string $id ): ?object. */
    private $loaders;

    /** @var array Ref key => loaded object. */
    private $loaded = [];

    /**
     * @param callable[]|null $loaders Entity type id => loader; null = the WooCommerce loaders.
     */
    public function __construct( ?array $loaders = null ) {
        $this->loaders = $loaders ?? self::default_loaders();
    }

    /**
     * The entity, loaded once per resolver.
     *
     * @param Ref $ref Entity to load.
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

    /**
     * Hands over an object already loaded, e.g. the order a hook passed in.
     *
     * @param Ref    $ref    Entity it is.
     * @param object $entity The loaded object.
     */
    public function prime( Ref $ref, $entity ): void {
        $this->loaded[ $ref->key() ] = $entity;
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
     * @param string $id Order id.
     */
    private static function order( string $id ) {
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $id ) : false;
        return $order instanceof \WC_Order ? $order : null;
    }
}
