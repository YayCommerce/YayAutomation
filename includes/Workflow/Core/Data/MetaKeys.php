<?php
namespace YayMatik\Workflow\Core\Data;

defined( 'ABSPATH' ) || exit;

/** Meta keys a workflow must not read or write. */
final class MetaKeys {

    private const OWN_PREFIX = '_yaymatik_';

    /** WooCommerce drops these when it reads order meta, so a value written there can't be read back. */
    private const HIDDEN_PREFIX = 'wp_';

    /**
     * WooCommerce's own order fields, keys it hides, and YayMatik's claims. Compared lowercase: `meta_key` collation ignores case.
     *
     * @param \WC_Order $order Any order: a blank one has the same data store.
     * @param string    $key
     */
    public static function order_blocked( \WC_Order $order, string $key ): bool {
        $key = strtolower( $key );
        return 0 === strpos( $key, self::OWN_PREFIX )
            || 0 === strpos( $key, self::HIDDEN_PREFIX )
            || in_array( $key, array_map( 'strtolower', $order->get_data_store()->get_internal_meta_keys() ), true );
    }

    /**
     * What decides a user's access and sessions, and YayMatik's claims.
     *
     * @param string $key
     */
    public static function user_blocked( string $key ): bool {
        global $wpdb;
        $key = strtolower( $key );
        return 'session_tokens' === $key
            || 0 === strpos( $key, self::OWN_PREFIX )
            || 1 === preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '(\d+_)?(capabilities|user_level|user-settings|user-settings-time)$/i', $key );
    }
}
