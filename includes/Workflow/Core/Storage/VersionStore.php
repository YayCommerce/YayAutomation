<?php
namespace YayMatik\Workflow\Core\Storage;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** Rows of the `workflow_versions` table: one graph snapshot per version, dates in GMT. Writes return false on failure. */
final class VersionStore {

    public const ACTION_CREATED     = 'created';
    public const ACTION_EDITED      = 'edited';
    public const ACTION_ACTIVATED   = 'activated';
    public const ACTION_DEACTIVATED = 'deactivated';

    public const PER_PAGE = 10;

    public static function table(): string {
        return Schema::table( Schema::WORKFLOW_VERSIONS );
    }

    /**
     * One version with its graph decoded, or null.
     *
     * @param int $id
     */
    public static function find( int $id ): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
        if ( ! $row ) {
            return null;
        }
        $graph = json_decode( $row['graph'], true );
        return [
            'id'          => (int) $row['id'],
            'workflow_id' => (int) $row['workflow_id'],
            'action'      => $row['action'],
            'graph'       => is_array( $graph ) ? $graph : [],
            'user_id'     => (int) $row['user_id'],
            'created_at'  => $row['created_at'],
            'updated_at'  => $row['updated_at'],
        ];
    }

    /**
     * A row by the current user, now.
     *
     * @param int    $workflow_id
     * @param string $action One of the ACTION_ constants.
     * @param array  $graph
     * @return int Row id; 0 when the insert failed.
     */
    public static function insert( int $workflow_id, string $action, array $graph ): int {
        global $wpdb;
        $time = current_time( 'mysql', true );
        $ok   = $wpdb->insert(
            self::table(),
            [
                'workflow_id' => $workflow_id,
                'action'      => $action,
                'graph'       => wp_json_encode( $graph ),
                'user_id'     => get_current_user_id(),
                'created_at'  => $time,
                'updated_at'  => $time,
            ],
            [ '%d', '%s', '%s', '%d', '%s', '%s' ]
        );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Replaces a row's snapshot, by the current user, now; only for a row no run pins.
     *
     * @param int    $id
     * @param string $action One of the ACTION_ constants.
     * @param array  $graph
     */
    public static function update( int $id, string $action, array $graph ): bool {
        return self::write(
            $id,
            [
                'action'  => $action,
                'graph'   => wp_json_encode( $graph ),
                'user_id' => get_current_user_id(),
            ]
        );
    }

    /**
     * Moves a row's time to now, nothing else.
     *
     * @param int $id
     */
    public static function touch( int $id ): bool {
        return self::write( $id, [] );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return false !== $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
    }

    public static function delete_for_workflow( int $workflow_id ): bool {
        global $wpdb;
        return false !== $wpdb->delete( self::table(), [ 'workflow_id' => $workflow_id ], [ '%d' ] );
    }

    /**
     * Newest change first, up to PER_PAGE + 1 rows so the caller can tell whether a next page exists.
     *
     * @param int $workflow_id
     * @param int $page 1-based.
     * @return array[] Rows with `graph` still JSON.
     */
    public static function page( int $workflow_id, int $page ): array {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT id, action, graph, user_id, updated_at FROM {$table} WHERE workflow_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d",
                $workflow_id,
                self::PER_PAGE + 1,
                ( max( 1, $page ) - 1 ) * self::PER_PAGE
            ),
            ARRAY_A
        );
    }

    /**
     * @param string $gmt GMT `Y-m-d H:i:s`.
     */
    public static function to_iso( string $gmt ): string {
        return ( new \DateTimeImmutable( $gmt, new \DateTimeZone( 'UTC' ) ) )->format( 'c' );
    }

    private static function write( int $id, array $fields ): bool {
        global $wpdb;
        $fields['updated_at'] = current_time( 'mysql', true );
        return false !== $wpdb->update( self::table(), $fields, [ 'id' => $id ] );
    }
}
