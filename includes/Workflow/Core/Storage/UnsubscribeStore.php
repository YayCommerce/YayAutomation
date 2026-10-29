<?php
namespace YayMatik\Workflow\Core\Storage;

use YayMatik\Workflow\Core\Email\UnsubscribeLink;

defined( 'ABSPATH' ) || exit;

/** Addresses that get no customer emails from any workflow, normalized by `UnsubscribeLink::normalize()`. */
final class UnsubscribeStore {

    /**
     * @param string $email Any case.
     */
    public static function has( string $email ): bool {
        $email = UnsubscribeLink::normalize( $email );
        if ( '' === $email ) {
            return false;
        }
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, read before each customer email.
        return null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s", $email ) );
    }

    /**
     * @param string $email Normalized.
     * @return array|null `email`, `created_at` (GMT).
     */
    public static function find( string $email ): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, one row by its unique key.
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT email, created_at FROM {$table} WHERE email = %s", $email ), ARRAY_A );
        return $row ? $row : null;
    }

    /**
     * Already unsubscribed is fine.
     *
     * @param string $email Any case.
     * @return bool False when it isn't an email or the write failed.
     */
    public static function add( string $email ): bool {
        $email = UnsubscribeLink::normalize( $email );
        if ( '' === $email ) {
            return false;
        }
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, a write; IGNORE keeps the first date.
        $done = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (email, created_at) VALUES (%s, %s)", $email, current_time( 'mysql', true ) ) );
        return false !== $done;
    }

    private static function table(): string {
        return Schema::table( Schema::UNSUBSCRIBES );
    }
}
