<?php
namespace YayMatik\Api;

defined( 'ABSPATH' ) || exit;

/** The customer a data field on `customer` reads, the same whatever the trigger (order, cart, account, review). */
interface CustomerView {

    public function email(): string;

    /** 0 for a guest. */
    public function user_id(): int;

    public function first_name(): string;

    public function last_name(): string;

    /** 0 unless the customer comes from an order. */
    public function order_id(): int;
}
