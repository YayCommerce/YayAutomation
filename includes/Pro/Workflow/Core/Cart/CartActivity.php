<?php
namespace YayMatik\Pro\Workflow\Core\Cart;

use YayMatik\Workflow\Core\Storage\CartStore;
use YayMatik\Workflow\Core\Storage\Schema;
use YayMatik\Workflow\Core\Storage\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * When a signed-in person last added to their cart. Tracked only while YayMatik Pro runs with a license, so the time it
 * resumed counts as an add: after a gap the field reads later than reality, never earlier.
 */
final class CartActivity {

    /** User meta: UTC Unix time of the last add or quantity increase. */
    public const META = '_yaymatik_last_added_to_cart';

    /** UTC Unix time tracking last (re)started. */
    public const RESUMED = 'yaymatik_cart_tracking_resumed_at';

    /** '1' from deactivation until the next licensed boot, which resumes. */
    public const PAUSED = 'yaymatik_cart_tracking_paused';

    /** The license state the last request saw: '1' allowed, '0' not. */
    public const LICENSED = 'yaymatik_cart_tracking_licensed';

    /**
     * Before the plugin boots, on every request.
     *
     * @param bool $licensed
     */
    public static function register( bool $licensed ): void {
        add_action( 'deactivate_' . YAYMATIK_BASE_NAME, [ self::class, 'pause' ] );
        $known = get_option( self::LICENSED, null );
        if ( ! $licensed ) {
            if ( '0' !== $known ) {
                update_option( self::LICENSED, '0', true );
            }
            return;
        }
        if ( false === get_option( self::RESUMED ) || '1' === get_option( self::PAUSED ) || '0' === $known ) {
            update_option( self::RESUMED, time(), true );
            update_option( self::PAUSED, '0', true );
        }
        if ( '1' !== $known ) {
            update_option( self::LICENSED, '1', true );
        }
        add_action( 'woocommerce_add_to_cart', [ self::class, 'added' ] );
        add_action( 'woocommerce_after_cart_item_quantity_update', [ self::class, 'quantity' ], 10, 3 );
    }

    public static function pause(): void {
        update_option( self::PAUSED, '1', true );
    }

    /** On `woocommerce_add_to_cart`: a new line, or more of one already there. */
    public static function added(): void {
        $user_id = get_current_user_id();
        if ( $user_id ) {
            update_user_meta( $user_id, self::META, time() );
        }
    }

    /**
     * On `woocommerce_after_cart_item_quantity_update`: an increase only. Lowering, removing and WooCommerce's own stock fixes don't count.
     *
     * @param string $cart_item_key
     * @param int    $quantity
     * @param int    $old_quantity
     */
    public static function quantity( $cart_item_key, $quantity, $old_quantity ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- the hook's arguments.
        if ( (int) $quantity > (int) $old_quantity ) {
            self::added();
        }
    }

    /**
     * The later of the last add and the time tracking resumed; null for a guest or when neither is known.
     *
     * @param int $user_id
     */
    public static function last_added( int $user_id ): ?int {
        if ( $user_id <= 0 ) {
            return null;
        }
        $at = max( (int) get_user_meta( $user_id, self::META, true ), (int) get_option( self::RESUMED, 0 ) );
        return $at > 0 ? $at : null;
    }

    /**
     * Items (quantities) in the person's tracked cart that isn't ordered; null for a guest or while carts aren't tracked.
     *
     * @param int $user_id
     */
    public static function item_count( int $user_id ): ?float {
        if ( $user_id <= 0 || ! Settings::get( 'cart_tracking' ) ) {
            return null;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, read when a rule asks.
        $items = $wpdb->get_var( $wpdb->prepare( 'SELECT items FROM %i WHERE user_id = %d AND status <> %s ORDER BY updated_at DESC LIMIT 1', Schema::table( Schema::CARTS ), $user_id, CartStore::ORDERED ) );
        $lines = json_decode( (string) $items, true );
        return (float) array_sum( array_map( fn( $line ) => (int) ( $line['quantity'] ?? 0 ), is_array( $lines ) ? $lines : [] ) );
    }
}
