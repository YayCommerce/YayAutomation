<?php
namespace YayMatik\Pro\Workflow\Core\Session;

defined( 'ABSPATH' ) || exit;

/** When a signed-in person was last seen on the store, as a UTC Unix time in user meta. */
final class LastSeen {

    public const KEY = '_yaymatik_last_seen';

    /**
     * @param int $user_id
     */
    public static function get( int $user_id ): ?int {
        $value = get_user_meta( $user_id, self::KEY, true );
        return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Moves it to `$now` when it is older than `$min_age` seconds; true only for the request whose write moved it.
     *
     * @param int $user_id
     * @param int $now
     * @param int $min_age
     */
    public static function advance( int $user_id, int $now, int $min_age ): bool {
        global $wpdb;
        // One UPDATE decides between parallel requests: only the one that still finds the old value changes the row.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a conditional write update_user_meta() can't do; the cache is dropped below.
        $done = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET meta_value = %d WHERE user_id = %d AND meta_key = %s AND CAST( meta_value AS UNSIGNED ) < %d', $wpdb->usermeta, $now, $user_id, self::KEY, $now - $min_age ) );
        if ( ! $done ) {
            return false;
        }
        wp_cache_delete( $user_id, 'user_meta' );
        return true;
    }

    /**
     * Sets it to `$now`, adding it the first time.
     *
     * @param int $user_id
     * @param int $now
     */
    public static function set( int $user_id, int $now ): void {
        if ( ! self::advance( $user_id, $now, 0 ) ) {
            add_user_meta( $user_id, self::KEY, $now, true );
        }
    }
}
