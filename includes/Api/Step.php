<?php
namespace YayMatik\Api;

defined( 'ABSPATH' ) || exit;

/** The step an action's `handle` gets: its data, and how it ends (docs/extension-api.md §4.3). */
interface Step {

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

    /** Sample or made-up data: Preview and Send test. */
    public function is_preview(): bool;

    /** An email action's unsubscribe link for its footer: `#` in Preview and Send test, '' outside an email's `build`. */
    public function unsubscribe_url(): string;

    /** The step must do nothing real (today: an email's `build` in Send test); an action with a side effect skips it. */
    public function is_test(): bool;

    /** The run step's key: the same across its retries and replays, one per loop item; '' outside a run. A grant keeps it to run once. */
    public function key(): string;

    /**
     * Return it from `handle`: the step is done.
     *
     * @param array $outputs
     * @return mixed
     */
    public function done( array $outputs = [] );

    /**
     * Return it from `handle`: nothing to do; the run goes on.
     *
     * @param string $reason
     * @return mixed
     */
    public function skip( string $reason );

    /**
     * Return it from `handle`: an error; `on_error` decides.
     *
     * @param string $reason
     * @return mixed
     */
    public function fail( string $reason );
}
