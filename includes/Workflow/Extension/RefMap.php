<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\DataItems\CartData;
use YayMatik\Workflow\DataItems\CustomerData;
use YayMatik\Workflow\DataItems\OrderData;
use YayMatik\Workflow\DataItems\ReviewData;

defined( 'ABSPATH' ) || exit;

/**
 * A `yaymatik_fire` payload's ids as the refs and subject core triggers use: the customer comes
 * from the first of order → cart → review → user, as `customer` + `order:12` (or `cart:9`, `review:3`, `user:5`).
 * A module's own data item comes under `<item>_id` and is the subject only when nothing else is.
 *
 * @internal
 */
final class RefMap {

    /** Payload key => subject prefix, in subject order. */
    public const IDS = [
        'order_id'  => OrderData::ID,
        'cart_id'   => CartData::ID,
        'review_id' => ReviewData::ID,
        'user_id'   => 'user',
    ];

    /**
     * Payload id key => data item: core's, then the module's own.
     *
     * @param string[] $items The module's data item ids.
     */
    public static function keys( array $items ): array {
        $keys = self::IDS;
        foreach ( $items as $item ) {
            $keys[ $item . '_id' ] = $item;
        }
        return $keys;
    }

    /**
     * Key => Ref; a `user_id` that isn't the customer stays a plain id, for `Step::user()`.
     *
     * @param array    $args  Payload with valid ids.
     * @param string[] $items
     */
    public static function refs( array $args, array $items ): array {
        $refs     = [];
        $customer = null;
        foreach ( self::keys( $items ) as $key => $source ) {
            if ( ! isset( $args[ $key ] ) ) {
                continue;
            }
            if ( 'user' !== $source ) {
                $refs[ $source ] = new Ref( $source, $args[ $key ] );
            }
            if ( ! isset( self::IDS[ $key ] ) ) {
                continue;
            }
            if ( ! $customer ) {
                $customer = new Ref( CustomerData::ID, $source . ':' . $args[ $key ] );
            } elseif ( 'user' === $source ) {
                $refs[ $key ] = $args[ $key ];
            }
        }
        if ( $customer ) {
            $refs[ CustomerData::ID ] = $customer;
        }
        return $refs;
    }

    /**
     * `order:12`, `cart:9`, `review:3`, `user:5`, or `<item>:<id>`; '' when the payload has no id.
     *
     * @param array    $args
     * @param string[] $items
     */
    public static function subject( array $args, array $items ): string {
        foreach ( self::keys( $items ) as $key => $source ) {
            if ( isset( $args[ $key ] ) ) {
                return $source . ':' . $args[ $key ];
            }
        }
        return '';
    }

    /**
     * Whether a payload gives this data item.
     *
     * @param string   $item
     * @param array    $args
     * @param string[] $items
     */
    public static function gives( string $item, array $args, array $items ): bool {
        if ( CustomerData::ID === $item ) {
            return '' !== self::subject( $args, [] );
        }
        return isset( self::keys( $items )[ $item . '_id' ], $args[ $item . '_id' ] );
    }
}
