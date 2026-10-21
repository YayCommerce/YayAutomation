<?php
namespace YayMatik\Workflow\Core\Coupon;

defined( 'ABSPATH' ) || exit;

/** WooCommerce coupons (`WC_Coupon`). */
final class WcCouponWriter implements CouponWriter {

    /** Meta on every coupon a workflow made, for finding or cleaning them up later. */
    public const META = '_yaymatik_generated';

    /** Which run and step made it: for erasing a customer's data, cleanup and support. */
    public const META_RUN  = '_yaymatik_run_id';
    public const META_NODE = '_yaymatik_node_id';

    public function exists( string $code ): bool {
        return (bool) wc_get_coupon_id_by_code( $code );
    }

    public function create( array $coupon ): int {
        $wc = new \WC_Coupon();
        $wc->set_code( $coupon['code'] );
        $wc->set_discount_type( $coupon['discount_type'] );
        $wc->set_amount( $coupon['amount'] );
        $wc->set_free_shipping( $coupon['free_shipping'] );
        $wc->set_date_expires( $coupon['expires'] );
        $wc->set_minimum_amount( null === $coupon['minimum_amount'] ? '' : $coupon['minimum_amount'] );
        $wc->set_individual_use( $coupon['individual_use'] );
        // WooCommerce checks these against the billing email at checkout, so guests are covered too.
        $wc->set_email_restrictions( '' === $coupon['email'] ? [] : [ $coupon['email'] ] );
        $wc->set_usage_limit( 1 );
        $wc->set_usage_limit_per_user( 1 );
        $wc->set_description( $coupon['description'] );
        $wc->update_meta_data( self::META, 'yes' );
        if ( $coupon['run_id'] > 0 ) {
            $wc->update_meta_data( self::META_RUN, $coupon['run_id'] );
            $wc->update_meta_data( self::META_NODE, $coupon['node_id'] );
        }
        return (int) $wc->save();
    }
}
