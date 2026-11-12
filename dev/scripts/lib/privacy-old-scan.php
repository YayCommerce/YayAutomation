<?php
/**
 * The privacy lookups as they were before `run_emails`: a LIKE / REGEXP scan of every run and step. Kept only as
 * the reference for check-privacy-index and bench-privacy; the plugin never loads it.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\Schema;
use YayMatik\Workflow\Core\Storage\TriggerClaimStore;

const YAYMATIK_OLD_IN_PROGRESS = [ RunStore::STATUS_QUEUED, RunStore::STATUS_RUNNING ];

/**
 * `WHERE` part over `r` and its params.
 *
 * @param string   $email
 * @param string[] $subjects
 * @param bool     $waiting_subjects
 */
function yaymatik_old_about( string $email, array $subjects, bool $waiting_subjects ): array {
    global $wpdb;
    $like   = fn( string $value ) => '%' . $wpdb->esc_like( (string) wp_json_encode( $value ) ) . '%';
    $regexp = '(^|[^A-Za-z0-9._%+-])' . addcslashes( $email, '.[]()*+?{}|^$\\' ) . '($|[^A-Za-z0-9.-]|[.]($|[^A-Za-z0-9]))';
    $sql    = 'r.scope LIKE %s OR r.scope LIKE %s OR r.error REGEXP %s OR EXISTS (SELECT 1 FROM %i s WHERE s.run_id = r.id AND (s.input LIKE %s OR s.output LIKE %s OR s.error REGEXP %s))';
    $params = [ $like( $email ), $like( TriggerClaimStore::email_key( $email ) ), $regexp, RunStepStore::table(), $like( $email ), $like( $email ), $regexp ];
    if ( $subjects ) {
        $sql   .= ' OR (r.subject IN (' . Schema::in( count( $subjects ), '%s' ) . ')' . ( $waiting_subjects ? ' AND r.status = %s' : '' ) . ')';
        $params = array_merge( $params, array_values( $subjects ), $waiting_subjects ? [ RunStore::STATUS_WAITING ] : [] );
    }
    return [ $sql, $params ];
}

/**
 * @param string   $email
 * @param string[] $subjects
 * @param int      $limit
 * @param int      $offset
 */
function yaymatik_old_for_export( string $email, array $subjects, int $limit, int $offset ): array {
    global $wpdb;
    list( $about, $params ) = yaymatik_old_about( $email, $subjects, false );
    return (array) $wpdb->get_results( $wpdb->prepare( "SELECT r.id, r.status, r.started_at, w.name AS workflow_name FROM %i r LEFT JOIN %i w ON w.id = r.workflow_id WHERE {$about} ORDER BY r.id LIMIT %d OFFSET %d", array_merge( [ RunStore::table(), Schema::table( Schema::WORKFLOWS ) ], $params, [ $limit, $offset ] ) ), ARRAY_A );
}

/**
 * @param string   $email
 * @param string[] $subjects
 * @param int      $limit
 */
function yaymatik_old_ids_mentioning( string $email, array $subjects, int $limit ): array {
    global $wpdb;
    list( $about, $params ) = yaymatik_old_about( $email, $subjects, true );
    $rows                   = $wpdb->get_results( $wpdb->prepare( 'SELECT r.id, r.status FROM %i r WHERE r.status NOT IN (' . Schema::in( 2, '%s' ) . ") AND ({$about}) ORDER BY r.id LIMIT %d", array_merge( [ RunStore::table() ], YAYMATIK_OLD_IN_PROGRESS, $params, [ $limit ] ) ), ARRAY_A );
    return array_column( (array) $rows, 'status', 'id' );
}

/**
 * @param string   $email
 * @param string[] $subjects
 */
function yaymatik_old_in_progress_mentioning( string $email, array $subjects ): bool {
    global $wpdb;
    list( $about, $params ) = yaymatik_old_about( $email, $subjects, false );
    return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT r.id FROM %i r WHERE r.status IN (' . Schema::in( 2, '%s' ) . ") AND ({$about}) LIMIT 1", array_merge( [ RunStore::table() ], YAYMATIK_OLD_IN_PROGRESS, $params ) ) );
}
