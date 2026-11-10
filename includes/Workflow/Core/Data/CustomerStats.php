<?php
namespace YayMatik\Workflow\Core\Data;

use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

// Own SQL: WooCommerce's totals don't subtract refunds and only know accounts.

/** A person's paid orders: an account plus a billing email; amounts are summed as stored, whatever their currency. */
final class CustomerStats {

    /**
     * @param int    $user_id
     * @param string $email
     * @return array{count: int, spent: float, last_paid: int|null} `last_paid` is the Unix time of the newest paid order.
     * @throws \RuntimeException When a query fails, so a database error never reads as "no orders".
     */
    public static function totals( int $user_id, string $email ): array {
        global $wpdb;
        $set = self::paid_set( $user_id, $email );
        if ( '' === $set ) {
            return [
                'count'     => 0,
                'spent'     => 0.0,
                'last_paid' => null,
            ];
        }
        $row = $wpdb->get_row( "SELECT COUNT(*) AS paid, COALESCE(SUM(s.total), 0) AS total, MAX(s.created) AS newest FROM ({$set}) s", ARRAY_A );
        self::check();
        // Refund totals are stored negative.
        $refunds = (float) $wpdb->get_var( self::refunds_sql( $set ) );
        self::check();
        return [
            'count'     => (int) ( $row['paid'] ?? 0 ),
            // Two floats: 150.7 - 50.7 would miss "total spent is 100".
            'spent'     => round( (float) ( $row['total'] ?? 0 ) + $refunds, 8 ),
            'last_paid' => empty( $row['newest'] ) ? null : (int) strtotime( $row['newest'] . ' UTC' ),
        ];
    }

    /**
     * Parent product ids of everything the person paid for; a variation counts as its parent.
     *
     * @param int    $user_id
     * @param string $email
     * @return string[]
     * @throws \RuntimeException When the query fails.
     */
    public static function product_ids( int $user_id, string $email ): array {
        global $wpdb;
        $set = self::paid_set( $user_id, $email );
        if ( '' === $set ) {
            return [];
        }
        $items = $wpdb->prefix . 'woocommerce_order_items';
        $meta  = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $ids   = $wpdb->get_col(
            "SELECT DISTINCT m.meta_value FROM {$meta} m
            JOIN {$items} i ON i.order_item_id = m.order_item_id
            WHERE m.meta_key = '_product_id' AND i.order_item_type = 'line_item' AND i.order_id IN (SELECT s.id FROM ({$set}) s)"
        );
        self::check();
        return array_map( 'strval', $ids );
    }

    /**
     * SQL for the person's paid orders as rows of `id`, `total`, `created`; '' with no account and no email.
     *
     * @param int    $user_id
     * @param string $email
     */
    private static function paid_set( int $user_id, string $email ): string {
        global $wpdb;
        $email = strtolower( trim( $email ) );
        if ( $user_id <= 0 && '' === $email ) {
            return '';
        }
        $statuses = implode( ', ', array_map( fn( string $status ) => $wpdb->prepare( '%s', 'wc-' . $status ), wc_get_is_paid_statuses() ) );
        $hpos     = OrderUtil::custom_orders_table_usage_is_enabled();
        $user_col = $hpos ? 'o.customer_id' : 'u.meta_value';
        $mail_col = $hpos ? 'o.billing_email' : 'e.meta_value';
        $person   = [];
        if ( $user_id > 0 ) {
            $person[] = $wpdb->prepare( "{$user_col} = %d", $user_id );
        }
        if ( '' !== $email ) {
            $person[] = $wpdb->prepare( "{$mail_col} = %s", $email );
        }
        $person = implode( ' OR ', $person );
        if ( $hpos ) {
            $orders = $wpdb->prefix . 'wc_orders';
            return "SELECT o.id AS id, o.total_amount AS total, o.date_created_gmt AS created FROM {$orders} o
                WHERE o.type = 'shop_order' AND o.status IN ({$statuses}) AND ({$person})";
        }
        return "SELECT p.ID AS id, CAST(t.meta_value AS DECIMAL(26,8)) AS total, p.post_date_gmt AS created FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = '_customer_user'
            LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_billing_email'
            LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_order_total'
            WHERE p.post_type = 'shop_order' AND p.post_status IN ({$statuses}) AND ({$person})";
    }

    /**
     * SQL for the sum of the refunds of a set of orders.
     *
     * @param string $set From `paid_set()`.
     */
    private static function refunds_sql( string $set ): string {
        global $wpdb;
        if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
            $orders = $wpdb->prefix . 'wc_orders';
            return "SELECT COALESCE(SUM(r.total_amount), 0) FROM {$orders} r
                WHERE r.type = 'shop_order_refund' AND r.parent_order_id IN (SELECT s.id FROM ({$set}) s)";
        }
        return "SELECT COALESCE(SUM(CAST(t.meta_value AS DECIMAL(26,8))), 0) FROM {$wpdb->posts} r
            LEFT JOIN {$wpdb->postmeta} t ON t.post_id = r.ID AND t.meta_key = '_order_total'
            WHERE r.post_type = 'shop_order_refund' AND r.post_parent IN (SELECT s.id FROM ({$set}) s)";
    }

    /** @throws \RuntimeException When the last query failed. */
    private static function check(): void {
        global $wpdb;
        if ( '' !== $wpdb->last_error ) {
            throw new \RuntimeException( esc_html( 'Could not read the orders of the customer: ' . $wpdb->last_error ) );
        }
    }
}
