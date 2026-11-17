<?php
namespace YayMatik\Api;

defined( 'ABSPATH' ) || exit;

/** The saved cart a data field on `cart` reads. */
interface CartView {

    public function id(): int;

    public function email(): string;

    /** 0 for a guest. */
    public function user_id(): int;

    public function currency(): string;

    public function total(): float;

    /** @return array[] Lines: `product_id`, `variation_id`, `quantity`, `line_total`. */
    public function items(): array;
}
