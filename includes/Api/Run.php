<?php
namespace YayMatik\Api;

defined( 'ABSPATH' ) || exit;

/** The run an action's `run` gets: its data, and how the step ends (docs/extension-api.md §4.3). */
interface Run {

    /** @return \WC_Order|null */
    public function order();

    /**
     * The order's account when there is one, else the payload's `user_id`; null for a guest.
     *
     * @return \WP_User|null
     */
    public function user();

    /** '' when there is none. */
    public function customer_email(): string;

    /** @return object|null */
    public function cart();

    /**
     * The loaded object of a data item, or null.
     *
     * @param string $id
     * @return object|null
     */
    public function item( string $id );

    /**
     * A value by merge-tag path, e.g. `customer.first_name`, `steps.coupon.code`; null when missing.
     *
     * @param string $path
     * @return mixed
     */
    public function get( string $path );

    /**
     * `$text` with its merge tags filled.
     *
     * @param string $text
     * @param bool   $html Escape the values for HTML.
     */
    public function render( string $text, bool $html = false ): string;

    public function is_preview(): bool;

    /**
     * Return it from `run`: the step is done.
     *
     * @param array $outputs
     * @return mixed
     */
    public function done( array $outputs = [] );

    /**
     * Return it from `run`: nothing to do; the run goes on.
     *
     * @param string $reason
     * @return mixed
     */
    public function skip( string $reason );

    /**
     * Return it from `run`: an error; `on_error` decides.
     *
     * @param string $reason
     * @return mixed
     */
    public function fail( string $reason );
}
