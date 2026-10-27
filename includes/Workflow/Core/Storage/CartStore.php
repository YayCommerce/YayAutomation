<?php
namespace YayMatik\Workflow\Core\Storage;

defined( 'ABSPATH' ) || exit;

/** Rows of the `carts` table, one per WooCommerce session key. */
final class CartStore {

    public const ACTIVE  = 'active';
    public const ORDERED = 'ordered';

    /**
     * @param string $session_key
     * @return array|null
     */
    public static function find_by_session( string $session_key ): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, one row by its unique key.
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_key = %s", $session_key ), ARRAY_A );
        return $row ? $row : null;
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
        $columns = implode( ', ', array_map( fn( $column ) => "`{$column}`", array_keys( $data ) ) );
        $updates = implode( ', ', array_map( fn( $column ) => "`{$column}` = VALUES(`{$column}`)", array_merge( array_keys( $fields ), [ 'updated_at' ] ) ) );
        $table   = self::table();
        $sql     = "INSERT INTO {$table} ({$columns}) VALUES (" . implode( ', ', $formats ) . ") ON DUPLICATE KEY UPDATE {$updates}";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- own table; columns are ours, values prepared.
        return false !== $wpdb->query( $wpdb->prepare( $sql, array_values( $data ) ) );
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, indexed by email.
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email = %s AND updated_at >= %s", $email, $since ) );
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, a write.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE session_key = %s AND id <> %d", (string) $user_id, (int) $guest['id'] ) );
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, a write.
        $done = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET order_id = %d, checkout_at = COALESCE(checkout_at, %s), email = IF(email = '', %s, email), first_name = IF(first_name = '', %s, first_name), last_activity_at = %s, updated_at = %s WHERE session_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
     * @return int Rows marked.
     */
    public static function mark_ordered_by_order( int $order_id ): int {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, a write.
        return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, updated_at = %s WHERE order_id = %d AND status <> %s", self::ORDERED, current_time( 'mysql', true ), $order_id, self::ORDERED ) );
    }

    /**
     * Active carts with this email, last active no later than the order was created.
     *
     * @param string $email Normalized.
     * @param string $before GMT datetime.
     * @return int Rows marked.
     */
    public static function mark_ordered_by_email( string $email, string $before ): int {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, a write.
        return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, updated_at = %s WHERE email = %s AND status = %s AND last_activity_at <= %s", self::ORDERED, current_time( 'mysql', true ), $email, self::ACTIVE, $before ) );
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
                'status'      => self::ACTIVE,
                'created_at'  => $now,
                'checkout_at' => null,
                'order_id'    => 0,
                'restored_at' => null,
                'updated_at'  => $now,
            ],
            [ 'id' => $id ]
        );
    }

    private static function table(): string {
        return Schema::table( Schema::CARTS );
    }
}
