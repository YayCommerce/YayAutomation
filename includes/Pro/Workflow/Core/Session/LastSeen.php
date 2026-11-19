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
        if ( $done > 1 ) {
            self::dedupe( $user_id );
        } else {
            wp_cache_delete( $user_id, 'user_meta' );
        }
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
            // usermeta has no unique key: two first visits at once can both add a row.
            self::dedupe( $user_id );
        }
    }

    /**
     * One row left: the lowest `umeta_id`, with the newest value.
     *
     * @param int $user_id
     */
    private static function dedupe( int $user_id ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the rows themselves, to remove duplicates; the cache is dropped below.
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT umeta_id, meta_value FROM %i WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id', $wpdb->usermeta, $user_id, self::KEY ), ARRAY_A );
        if ( is_array( $rows ) && count( $rows ) > 1 ) {
            $keep   = (int) $rows[0]['umeta_id'];
            $newest = max( array_map( 'intval', array_column( $rows, 'meta_value' ) ) );
            $extra  = array_map( 'intval', array_slice( array_column( $rows, 'umeta_id' ), 1 ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- the row kept, by primary key; the cache is dropped below.
            $wpdb->update( $wpdb->usermeta, [ 'meta_value' => $newest ], [ 'umeta_id' => $keep ], [ '%d' ], [ '%d' ] );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one %d per id.
            $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE umeta_id IN (' . implode( ', ', array_fill( 0, count( $extra ), '%d' ) ) . ')', array_merge( [ $wpdb->usermeta ], $extra ) ) );
        }
        wp_cache_delete( $user_id, 'user_meta' );
    }
}
