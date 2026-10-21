<?php
namespace YayMatik\Workflow\Coupon;

defined( 'ABSPATH' ) || exit;

/**
 * A free shipping coupon does nothing unless a shipping zone has an enabled Free shipping method
 * that a coupon can unlock. The admin app warns when none does.
 */
final class FreeShipping {

    /** A Free shipping method accepts a coupon alone ("coupon" or "either"). */
    public const COUPON = 'coupon';

    /** Only methods that need a coupon and a minimum order amount ("both"). */
    public const COUPON_AND_MINIMUM = 'coupon_and_minimum';

    /** No enabled Free shipping method accepts a coupon: free shipping coupons do nothing. */
    public const NONE = '';

    /** What a free shipping coupon needs to work, across all zones (incl. "Locations not covered by your other zones"). */
    public static function coupon_mode(): string {
        if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
            return self::NONE;
        }
        $zones   = array_map( fn( array $zone ) => (int) $zone['id'], \WC_Shipping_Zones::get_zones() );
        $zones[] = 0;
        $mode    = self::NONE;
        foreach ( $zones as $zone_id ) {
            foreach ( \WC_Shipping_Zones::get_zone( $zone_id )->get_shipping_methods( true ) as $method ) {
                if ( 'free_shipping' !== $method->id ) {
                    continue;
                }
                $requires = $method->get_option( 'requires' );
                if ( in_array( $requires, [ 'coupon', 'either' ], true ) ) {
                    return self::COUPON;
                }
                if ( 'both' === $requires ) {
                    $mode = self::COUPON_AND_MINIMUM;
                }
            }
        }
        return $mode;
    }
}
