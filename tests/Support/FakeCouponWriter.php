<?php
namespace YayMatik\Tests\Support;

use YayMatik\Workflow\Coupon\CouponWriter;

/** Coupons in memory: what Generate coupon asked to create, and which codes "exist". */
final class FakeCouponWriter implements CouponWriter {

    /** @var array[] Coupons created, in order. */
    public $created = [];

    /** @var string[] Codes asked about, in order. */
    public $checked = [];

    /** @var int The first N codes asked about exist. */
    public $taken = 0;

    /** @var int What `create()` returns; 0 = a failed save. */
    public $next_id = 501;

    public function exists( string $code ): bool {
        $this->checked[] = $code;
        return count( $this->checked ) <= $this->taken;
    }

    public function create( array $coupon ): int {
        $this->created[] = $coupon;
        return $this->next_id;
    }
}
