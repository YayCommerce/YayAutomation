<?php
namespace YayMatik\Workflow\Core\Storage;

use YayMatik\Utils\Logger;

defined( 'ABSPATH' ) || exit;

/** Rows of the `carts` table, one per WooCommerce session key. */
final class CartStore {

    public const ACTIVE    = 'active';
    public const ABANDONED = 'abandoned';
    public const ORDERED   = 'ordered';

    /**
     * @param string $session_key
     * @return array|null
     */
    public static function find_by_session( string $session_key ): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, one row by its unique key.
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE session_key = %s', $table, $session_key ), ARRAY_A );
        return $row ? $row : null;
    }

    /**
     * @param int $id
     * @return array|null
     */
    public static function find( int $id ): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, one row by id.
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
        return $row ? $row : null;
    }

    /**
     * A restored cart moves to the session that first changes it: the session's own row goes, the restored row takes its key.
     *
     * @param int    $id
     * @param string $session_key
     * @param int    $user_id 0 keeps the row's.
     */
    public static function bind( int $id, string $session_key, int $user_id ): void {
        global $wpdb;
        $table = self::table();
        $now   = current_time( 'mysql', true );
        // Only while the restored row is still open, so an order placed meanwhile doesn't cost this session its row.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, a write.
        $wpdb->query( $wpdb->prepare( 'DELETE own FROM %i own JOIN %i restored ON restored.id = %d AND restored.status <> %s WHERE own.session_key = %s AND own.id <> %d', $table, $table, $id, self::ORDERED, $session_key, $id ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, a write.
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET session_key = %s, user_id = IF(%d > 0, %d, user_id), restored_at = %s, updated_at = %s WHERE id = %d AND status <> %s',
                $table,
                $session_key,
                $user_id,
                $user_id,
                $now,
                $now,
                $id,
                self::ORDERED
            )
        );
    }

    /**
     * Inserts the session's row or updates the given columns; one query, so two requests can't both insert.
     *
     * @param string $session_key
     * @param array  $fields Column => int|float|string.
     */
    public static function upsert( string $session_key, array $fields ): bool {
        global $wpdb;
        $now     = current_time( 'mysql', true );
        $data    = $fields + [
            'session_key' => $session_key,
            'status'      => self::ACTIVE,
            'created_at'  => $now,
            'updated_at'  => $now,
        ];
        $formats = array_map(
            function ( $value ) {
                if ( is_int( $value ) ) {
                    return '%d';
                }
                return is_float( $value ) ? '%f' : '%s';
            },
            $data
        );
        $updated = array_merge( array_keys( $fields ), [ 'updated_at' ] );
        $args    = array_merge(
            [ self::table() ],
            array_keys( $data ),
            array_values( $data ),
            ...array_map( fn( $column ) => [ $column, $column ], $updated )
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
        return false !== $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one value per placeholder.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- only placeholders are built here.
                'INSERT INTO %i (' . Schema::in( count( $data ), '%i' ) . ') VALUES (' . implode( ', ', $formats ) . ') ON DUPLICATE KEY UPDATE ' . implode( ', ', array_fill( 0, count( $updated ), '%i = VALUES(%i)' ) ),
                $args
            )
        );
    }

    /**
     * Updates the session's row when there is one; never inserts.
     *
     * @param string $session_key
     * @param array  $fields Column => value.
     */
    public static function touch( string $session_key, array $fields ): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, a write.
        return (bool) $wpdb->update( self::table(), $fields + [ 'updated_at' => current_time( 'mysql', true ) ], [ 'session_key' => $session_key ] );
    }

    /**
     * @param string $session_key
     */
    public static function delete_by_session( string $session_key ): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, a write.
        return (bool) $wpdb->delete( self::table(), [ 'session_key' => $session_key ] );
    }

    /**
     * Rows holding this email, updated since then.
     *
     * @param string $email Normalized.
     * @param string $since GMT datetime.
     */
    public static function sessions_with_email( string $email, string $since ): int {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, indexed by email.
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE email = %s AND updated_at >= %s', $table, $email, $since ) );
    }

    /**
     * Login: the guest row becomes the user's, replacing the user's older row. No guest row → nothing changes.
     *
     * @param string $guest_key
     * @param int    $user_id
     * @param string $email
     * @param string $first_name
     */
    public static function rekey( string $guest_key, int $user_id, string $email, string $first_name ): bool {
        $guest = self::find_by_session( $guest_key );
        if ( ! $guest ) {
            return false;
        }
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, a write.
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE session_key = %s AND id <> %d', $table, (string) $user_id, (int) $guest['id'] ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, a write.
        return (bool) $wpdb->update(
            $table,
            [
                'session_key' => (string) $user_id,
                'user_id'     => $user_id,
                'email'       => $email,
                'first_name'  => $first_name,
                'updated_at'  => current_time( 'mysql', true ),
            ],
            [ 'id' => (int) $guest['id'] ]
        );
    }

    /**
     * Sets `order_id` (a newer order replaces an older one); `checkout_at`, email and first name only fill empty fields.
     *
     * @param string $session_key
     * @param int    $order_id
     * @param string $email Normalized.
     * @param string $first_name
     */
    public static function link_order( string $session_key, int $order_id, string $email, string $first_name ): bool {
        global $wpdb;
        $table = self::table();
        $now   = current_time( 'mysql', true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, a write.
        $done = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET status = IF(status = 'abandoned', 'active', status), order_id = %d, checkout_at = COALESCE(checkout_at, %s), email = IF(email = '', %s, email), first_name = IF(first_name = '', %s, first_name), last_activity_at = %s, updated_at = %s WHERE session_key = %s",
                $table,
                $order_id,
                $now,
                $email,
                $first_name,
                $now,
                $now,
                $session_key
            )
        );
        return (bool) $done;
    }

    /**
     * @param int $order_id
     * @return int[] Ids marked.
     */
    public static function mark_ordered_by_order( int $order_id ): array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, uses KEY order_id.
        $ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE order_id = %d AND status <> %s', $table, $order_id, self::ORDERED ) ) );
        if ( $ids ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- own table, a write; Schema::in() gives one %d per id.
            $wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s, updated_at = %s WHERE id IN (' . Schema::in( count( $ids ) ) . ') AND status <> %s', array_merge( [ $table, self::ORDERED, current_time( 'mysql', true ) ], $ids, [ self::ORDERED ] ) ) );
        }
        return $ids;
    }

    /**
     * Active and abandoned carts with this email, last active no later than the order was created.
     *
     * @param string $email Normalized.
     * @param string $before GMT datetime.
     * @param int    $order_id
     * @return int[] Ids marked.
     */
    public static function mark_ordered_by_email( string $email, string $before, int $order_id ): array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, indexed by email.
        $ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s AND status IN (%s, %s) AND last_activity_at <= %s', $table, $email, self::ACTIVE, self::ABANDONED, $before ) ) );
        if ( $ids ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- own table, a write; Schema::in() gives one %d per id.
            $wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s, order_id = %d, updated_at = %s WHERE id IN (' . Schema::in( count( $ids ) ) . ') AND status <> %s', array_merge( [ $table, self::ORDERED, $order_id, current_time( 'mysql', true ) ], $ids, [ self::ORDERED ] ) ) );
        }
        return $ids;
    }

    /**
     * An ordered cart changed: a new cycle starts, so links signed with the old `created_at` stop working.
     *
     * @param int $id
     */
    public static function reset_cycle( int $id ): bool {
        global $wpdb;
        $now = current_time( 'mysql', true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, a write.
        return (bool) $wpdb->update(
            self::table(),
            [
                'status'          => self::ACTIVE,
                'created_at'      => $now,
                'abandoned_at'    => null,
                'abandon_trigger' => '',
                'series_at'       => null,
                'checkout_at'     => null,
                'order_id'        => 0,
                'restored_at'     => null,
                'updated_at'      => $now,
            ],
            [ 'id' => $id ]
        );
    }

    /**
     * Active carts with an email and items, last active inside the window, oldest first.
     *
     * @param string $before GMT datetime.
     * @param string $after  GMT datetime.
     * @param int    $limit
     * @return array[] `id`, `last_activity_at`, `checkout_at`, `series_at`.
     */
    public static function due( string $before, string $after, int $limit ): array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, uses KEY status.
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, last_activity_at, checkout_at, series_at FROM %i WHERE status = %s AND email <> '' AND items NOT IN ('', '[]') AND last_activity_at <= %s AND last_activity_at > %s ORDER BY last_activity_at, id LIMIT %d", $table, self::ACTIVE, $before, $after, $limit ), ARRAY_A );
    }

    /**
     * Only while the row is still active and no newer activity came in since it was read.
     *
     * @param int    $id
     * @param string $last_activity The `last_activity_at` that was read.
     * @return bool This call changed the row.
     */
    public static function mark_abandoned( int $id, string $last_activity ): bool {
        global $wpdb;
        // `updated_at` stays: the capture limit counts it as the shopper's activity.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, a write.
        return 1 === $wpdb->update(
            self::table(),
            [ 'status' => self::ABANDONED ],
            [
                'id'               => $id,
                'status'           => self::ACTIVE,
                'last_activity_at' => $last_activity,
            ]
        );
    }

    /** The newest cart with an email and items, of any status; 0 when there's none. */
    public static function newest_for_preview(): int {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, an admin action.
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE email <> '' AND items NOT IN ('', '[]') ORDER BY id DESC LIMIT 1", $table ) );
    }

    /**
     * Another open cart with this email started a reminder series since then.
     *
     * @param string $email Normalized.
     * @param int    $id    The cart asking.
     * @param string $since GMT datetime.
     */
    public static function series_taken( string $email, int $id, string $since ): bool {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, indexed by email.
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE email = %s AND id <> %d AND status <> %s AND series_at >= %s LIMIT 1', $table, $email, $id, self::ORDERED, $since ) );
    }

    /**
     * Locks the cart to one series, decided by this abandon; false when another series or an order came first.
     *
     * @param int    $id
     * @param string $kind The trigger id starting it.
     */
    public static function start_series( int $id, string $kind ): bool {
        global $wpdb;
        $now = current_time( 'mysql', true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, a write.
        return 1 === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET series_at = %s, abandoned_at = %s, abandon_trigger = %s WHERE id = %d AND series_at IS NULL AND status <> %s', self::table(), $now, $now, $kind, $id, self::ORDERED ) );
    }

    /**
     * Carts of any status last active before the cutoff, oldest ids first.
     *
     * @param string $before GMT datetime.
     * @param int    $limit
     * @return int[]
     */
    public static function inactive_ids_before( string $before, int $limit ): array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE last_activity_at < %s ORDER BY id LIMIT %d', $table, $before, $limit ) ) );
    }

    /**
     * @param int[] $ids
     * @return int|false Rows deleted.
     */
    public static function delete_ids( array $ids ) {
        global $wpdb;
        if ( ! $ids ) {
            return 0;
        }
        $table = self::table();
        $ids   = array_map( 'intval', $ids );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- own table, a write; Schema::in() gives one %d per id.
        $done = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . Schema::in( count( $ids ) ) . ')', array_merge( [ $table ], array_values( $ids ) ) ) );
        if ( false === $done ) {
            Logger::error( 'Could not delete old carts: ' . $wpdb->last_error );
            return false;
        }
        return (int) $done;
    }

    /**
     * @param string $email Normalized.
     * @return array[]
     */
    public static function for_email( string $email ): array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, indexed by email.
        return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id', $table, $email ), ARRAY_A );
    }

    /**
     * @param string $email Normalized.
     * @return int Rows deleted.
     */
    public static function delete_for_email( string $email ): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, a write.
        return (int) $wpdb->delete( self::table(), [ 'email' => $email ] );
    }

    private static function table(): string {
        return Schema::table( Schema::CARTS );
    }
}
