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
     * Key => Ref; a `user_id` that isn't the customer stays a plain id, for `Run::user()`.
     *
     * @param array $args Payload with valid ids.
     */
    public static function refs( array $args ): array {
        $refs     = [];
        $customer = null;
        foreach ( self::IDS as $key => $source ) {
            if ( ! isset( $args[ $key ] ) ) {
                continue;
            }
            if ( 'user' !== $source ) {
                $refs[ $source ] = new Ref( $source, $args[ $key ] );
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
     * `order:12`, `cart:9`, `review:3` or `user:5`; '' when the payload has no id.
     *
     * @param array $args
     */
    public static function subject( array $args ): string {
        foreach ( self::IDS as $key => $source ) {
            if ( isset( $args[ $key ] ) ) {
                return $source . ':' . $args[ $key ];
            }
        }
        return '';
    }

    /**
     * Whether a payload gives this data item.
     *
     * @param string $item
     * @param array  $args
     */
    public static function gives( string $item, array $args ): bool {
        if ( CustomerData::ID === $item ) {
            return '' !== self::subject( $args );
        }
        return isset( self::IDS[ $item . '_id' ], $args[ $item . '_id' ] );
    }
}
