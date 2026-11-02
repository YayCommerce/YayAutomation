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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, one row by its unique key.
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_key = %s", $session_key ), ARRAY_A );
        return $row ? $row : null;
    }

    /**
     * @param int $id
     * @return array|null
     */
    public static function find( int $id ): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, one row by id.
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, a write.
        $wpdb->query( $wpdb->prepare( "DELETE own FROM {$table} own JOIN {$table} restored ON restored.id = %d AND restored.status <> %s WHERE own.session_key = %s AND own.id <> %d", $id, self::ORDERED, $session_key, $id ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, a write.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET session_key = %s, user_id = IF(%d > 0, %d, user_id), restored_at = %s, updated_at = %s WHERE id = %d AND status <> %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
                "UPDATE {$table} SET status = IF(status = 'abandoned', 'active', status), order_id = %d, checkout_at = COALESCE(checkout_at, %s), email = IF(email = '', %s, email), first_name = IF(first_name = '', %s, first_name), last_activity_at = %s, updated_at = %s WHERE session_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, uses KEY order_id.
        $ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %d AND status <> %s", $order_id, self::ORDERED ) ) );
        if ( $ids ) {
            $in = implode( ',', $ids );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, a write; $in is a list of ints.
            $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, updated_at = %s WHERE id IN ({$in}) AND status <> %s", self::ORDERED, current_time( 'mysql', true ), self::ORDERED ) );
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, indexed by email.
        $ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s AND status IN (%s, %s) AND last_activity_at <= %s", $email, self::ACTIVE, self::ABANDONED, $before ) ) );
        if ( $ids ) {
            $in = implode( ',', $ids );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, a write; $in is a list of ints.
            $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, order_id = %d, updated_at = %s WHERE id IN ({$in}) AND status <> %s", self::ORDERED, $order_id, current_time( 'mysql', true ), self::ORDERED ) );
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
     * @return array[] `id`, `last_activity_at`, `checkout_at`, `abandoned_at`.
     */
    public static function due( string $before, string $after, int $limit ): array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, uses KEY status.
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, last_activity_at, checkout_at, abandoned_at FROM {$table} WHERE status = %s AND email <> '' AND items NOT IN ('', '[]') AND last_activity_at <= %s AND last_activity_at > %s ORDER BY last_activity_at, id LIMIT %d", self::ACTIVE, $before, $after, $limit ), ARRAY_A );
    }

    /**
     * Only while the row is still active and no newer activity came in since it was read.
     *
     * @param int    $id
     * @param string $last_activity The `last_activity_at` that was read.
     * @param string $kind          The cycle's first abandon: the trigger id to store. Empty on a later abandon.
     * @return bool This call changed the row.
     */
    public static function mark_abandoned( int $id, string $last_activity, string $kind ): bool {
        global $wpdb;
        $fields = [ 'status' => self::ABANDONED ];
        if ( '' !== $kind ) {
            $fields += [
                'abandoned_at'    => current_time( 'mysql', true ),
                'abandon_trigger' => $kind,
            ];
        }
        // `updated_at` stays: the capture limit counts it as the shopper's activity.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, a write.
        return 1 === $wpdb->update(
            self::table(),
            $fields,
            [
                'id'               => $id,
                'status'           => self::ACTIVE,
                'last_activity_at' => $last_activity,
            ]
        );
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, indexed by email.
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE email = %s AND id <> %d AND status <> %s AND series_at >= %s LIMIT 1", $email, $id, self::ORDERED, $since ) );
    }

    /**
     * @param int $id
     */
    public static function mark_series( int $id ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, a write.
        $wpdb->update( self::table(), [ 'series_at' => current_time( 'mysql', true ) ], [ 'id' => $id ] );
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE last_activity_at < %s ORDER BY id LIMIT %d", $before, $limit ) ) );
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
        $in    = implode( ',', array_map( 'intval', $ids ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, a write; $in is a list of ints.
        $done = $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" );
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, indexed by email.
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s ORDER BY id", $email ), ARRAY_A );
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
