<?php
namespace YayMatik\Workflow\Core\Coupon;

defined( 'ABSPATH' ) || exit;

/**
 * Where Generate coupon reads and writes WooCommerce coupons. `WcCouponWriter` on a site; a fake in
 * PHPUnit.
 */
interface CouponWriter {

    /**
     * Whether a coupon with this code already exists (any status).
     *
     * @param string $code Coupon code.
     */
    public function exists( string $code ): bool;

    /**
     * Creates and saves a coupon.
     *
     * @param array $coupon [
     *     'code'           => string,
     *     'discount_type'  => 'percent'|'fixed_cart',
     *     'amount'         => float, 0 for free shipping,
     *     'free_shipping'  => bool,
     *     'expires'        => int|null Unix time, null = never,
     *     'minimum_amount' => float|null,
     *     'individual_use' => bool,
     *     'email'          => string Allowed email, '' = anyone,
     *     'run_id'         => int Run that made it, 0 = none (a preview never makes one),
     *     'node_id'        => string Graph node of the step that made it,
     *     'description'    => string,
     * ]. Always single use, once per customer.
     * @return int The coupon id; 0 when it couldn't be saved.
     */
    public function create( array $coupon ): int;
}
