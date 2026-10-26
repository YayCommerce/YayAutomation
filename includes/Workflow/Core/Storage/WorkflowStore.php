<?php
namespace YayMatik\Workflow\Core\Storage;

use YayMatik\Utils\Logger;
use YayMatik\Workflow\Core\Steps\Step;
use YayMatik\Workflow\Core\Steps\StepRegistry;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** Workflows over the `workflows` table, input prepared by `Validator`; arrays match the TS `Workflow` type. */
final class WorkflowStore {

    public static function all(): array {
        $workflows = array_map( [ self::class, 'to_array' ], self::select( '', [], true ) );
        // Newest "Last updated" first, the date the list shows. ISO dates in UTC sort as strings.
        usort( $workflows, fn( array $a, array $b ) => strcmp( $b['lastVersionAt'], $a['lastVersionAt'] ) );
        return $workflows;
    }

    public static function find( int $id ): ?array {
        $rows = self::select( 'WHERE w.id = %d', [ $id ], true );
        return $rows ? self::to_array( $rows[0] ) : null;
    }

    /**
     * Read fresh: a run's slice must see a Deactivate from another request.
     *
     * @param int $id
     * @throws \RuntimeException When the query fails, so a database error never reads as "turned off".
     */
    public static function is_active( int $id ): bool {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $id ) );
        if ( null === $status && '' !== $wpdb->last_error ) {
            throw new \RuntimeException( 'Could not read the workflow status: ' . $wpdb->last_error );
        }
        return 'active' === $status;
    }

    /**
     * Version row holding the live graph, which a new run pins; 0 when the workflow doesn't exist.
     *
     * @param int $id
     */
    public static function live_version_id( int $id ): int {
        return (int) ( self::row( $id )['live_version_id'] ?? 0 );
    }

    /**
     * One page of versions, newest first, shaped like the TS `WorkflowVersion`.
     *
     * @param int $id
     * @param int $page 1-based.
     * @return array{items: array[], hasMore: bool}
     */
    public static function versions( int $id, int $page ): array {
        $workflow  = self::row( $id );
        $live      = (int) ( $workflow['live_version_id'] ?? 0 );
        $draft     = (int) ( $workflow['draft_version_id'] ?? 0 );
        $activated = null !== ( $workflow['activated_at'] ?? null );
        $active    = 'active' === ( $workflow['status'] ?? '' );
        $rows      = VersionStore::page( $id, $page );
        $has_more  = count( $rows ) > VersionStore::PER_PAGE;

        $items = array_map(
            function ( array $row ) use ( $live, $draft, $activated, $active ) {
                $row_id = (int) $row['id'];
                $status = null;
                // Never turned on: its only content is a draft, even though it's stored as the live graph.
                if ( $row_id === $draft || ( $row_id === $live && ! $activated ) ) {
                    $status = 'draft';
                } elseif ( $row_id === $live && $active ) {
                    $status = 'active';
                }
                $user = get_userdata( (int) $row['user_id'] );
                return [
                    'id'        => (string) $row_id,
                    'action'    => $row['action'],
                    // Null when the user has since been deleted.
                    'user'      => $user ? $user->display_name : null,
                    'createdAt' => VersionStore::to_iso( $row['updated_at'] ),
                    'graph'     => self::decode_graph( (string) $row['graph'] ),
                    'status'    => $status,
                ];
            },
            array_slice( $rows, 0, VersionStore::PER_PAGE )
        );

        return [
            'items'   => $items,
            'hasMore' => $has_more,
        ];
    }

    /**
     * Active workflows on a trigger, without their drafts: the runner only uses the live graph.
     *
     * @param string $step_id
     */
    public static function active_for_trigger( string $step_id ): array {
        return array_map( [ self::class, 'to_array' ], self::select( "WHERE w.status = 'active' AND w.trigger_id = %s", [ $step_id ], false ) );
    }

    /**
     * New inactive workflow.
     *
     * @param string $name
     * @param array  $graph
     * @return array|\WP_Error
     */
    public static function create( string $name, array $graph ) {
        $id    = 0;
        $saved = self::transaction(
            function () use ( $name, $graph, &$id ) {
                global $wpdb;
                $time = current_time( 'mysql', true );
                $ok   = $wpdb->insert(
                    self::table(),
                    [
                        'name'       => $name,
                        'status'     => 'inactive',
                        'trigger_id' => self::trigger_id( $graph ),
                        'created_at' => $time,
                        'updated_at' => $time,
                    ],
                    [ '%s', '%s', '%s', '%s', '%s' ]
                );
                if ( ! $ok ) {
                    return false;
                }
                $id      = (int) $wpdb->insert_id;
                $version = VersionStore::insert( $id, VersionStore::ACTION_CREATED, $graph );
                return $version && self::write( $id, [ 'live_version_id' => $version ] );
            }
        );
        return is_wp_error( $saved ) ? $saved : self::find( $id );
    }

    /**
     * Changes the live name and/or graph (null keeps it).
     *
     * @param int         $id
     * @param string|null $name
     * @param array|null  $graph
     * @return true|\WP_Error
     */
    public static function update_live( int $id, ?string $name, ?array $graph ) {
        return self::transaction(
            function () use ( $id, $name, $graph ) {
                $fields = null === $name ? [] : [ 'name' => $name ];
                if ( null !== $graph ) {
                    // Only a workflow never turned on edits its live graph: no run pins that version, so it's updated in place.
                    if ( ! VersionStore::update( self::live_version_id( $id ), VersionStore::ACTION_EDITED, $graph ) ) {
                        return false;
                    }
                    $fields['trigger_id'] = self::trigger_id( $graph );
                }
                return self::touch( $id, $fields );
            }
        );
    }

    /**
     * Sets or (null) clears the draft; `updated_at` stays. Its one version row is updated in place.
     *
     * @param int        $id
     * @param array|null $graph
     * @return true|\WP_Error
     */
    public static function save_draft( int $id, ?array $graph ) {
        $version = (int) ( self::row( $id )['draft_version_id'] ?? 0 );
        if ( null === $graph && ! $version ) {
            return true;
        }
        return self::transaction(
            function () use ( $id, $graph, $version ) {
                if ( null === $graph ) {
                    return VersionStore::delete( $version ) && self::write( $id, [ 'draft_version_id' => 0 ] );
                }
                if ( $version ) {
                    return VersionStore::update( $version, VersionStore::ACTION_EDITED, $graph );
                }
                $version = VersionStore::insert( $id, VersionStore::ACTION_EDITED, $graph );
                return $version && self::write( $id, [ 'draft_version_id' => $version ] );
            }
        );
    }

    /**
     * @param int $id
     * @return true|\WP_Error
     */
    public static function activate( int $id ) {
        return self::transaction(
            function () use ( $id ) {
                $version = self::copy_live( $id, VersionStore::ACTION_ACTIVATED );
                return $version && self::touch(
                    $id,
                    [
                        'status'          => 'active',
                        'activated_at'    => current_time( 'mysql', true ),
                        'live_version_id' => $version,
                    ]
                );
            }
        );
    }

    /**
     * Turns off; the draft is kept.
     *
     * @param int $id
     * @return true|\WP_Error
     */
    public static function deactivate( int $id ) {
        return self::transaction(
            function () use ( $id ) {
                $version = self::copy_live( $id, VersionStore::ACTION_DEACTIVATED );
                return $version && self::touch(
                    $id,
                    [
                        'status'          => 'inactive',
                        'live_version_id' => $version,
                    ]
                );
            }
        );
    }

    /**
     * Draft → live graph; its version row becomes the live one.
     *
     * @param int   $id
     * @param array $draft
     * @return true|\WP_Error
     */
    public static function apply_draft( int $id, array $draft ) {
        return self::transaction(
            function () use ( $id, $draft ) {
                $version = (int) ( self::row( $id )['draft_version_id'] ?? 0 );
                return $version && VersionStore::touch( $version ) && self::touch(
                    $id,
                    [
                        'trigger_id'       => self::trigger_id( $draft ),
                        'live_version_id'  => $version,
                        'draft_version_id' => 0,
                    ]
                );
            }
        );
    }

    /**
     * Permanent delete with its versions; its runs stay.
     *
     * @param int $id
     * @return true|\WP_Error
     */
    public static function delete( int $id ) {
        return self::transaction(
            function () use ( $id ) {
                global $wpdb;
                return VersionStore::delete_for_workflow( $id ) && false !== $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
            }
        );
    }

    /**
     * Runs `$writes` in one transaction; when it returns false, everything it wrote is rolled back.
     *
     * @param callable $writes Returns false when a write failed.
     * @return true|\WP_Error
     */
    private static function transaction( callable $writes ) {
        global $wpdb;
        try {
            if ( false !== $wpdb->query( 'START TRANSACTION' ) && $writes() && false !== $wpdb->query( 'COMMIT' ) ) {
                return true;
            }
        } catch ( \Throwable $e ) {
            // Left open, the transaction would swallow every later write in this request.
            $wpdb->query( 'ROLLBACK' );
            throw $e;
        }
        $error = $wpdb->last_error;
        $wpdb->query( 'ROLLBACK' );
        Logger::error( 'Could not save a workflow: ' . $error );
        return new \WP_Error( 'yaymatik_save_failed', __( 'Couldn’t save the workflow. Try again.', 'yaymatik' ), [ 'status' => 500 ] );
    }

    /**
     * New version row with the live graph; its id, 0 when a write failed.
     *
     * @param int    $id
     * @param string $action VersionStore::ACTION_ACTIVATED or ACTION_DEACTIVATED.
     */
    private static function copy_live( int $id, string $action ): int {
        $live = VersionStore::find( self::live_version_id( $id ) );
        return $live ? VersionStore::insert( $id, $action, $live['graph'] ) : 0;
    }

    /**
     * @param int   $id
     * @param array $fields
     */
    private static function write( int $id, array $fields ): bool {
        global $wpdb;
        return false !== $wpdb->update( self::table(), $fields, [ 'id' => $id ] );
    }

    /**
     * Writes `$fields` and moves `updated_at` to now.
     *
     * @param int   $id
     * @param array $fields
     */
    private static function touch( int $id, array $fields = [] ): bool {
        return self::write( $id, $fields + [ 'updated_at' => current_time( 'mysql', true ) ] );
    }

    private static function table(): string {
        return Schema::table( Schema::WORKFLOWS );
    }

    private static function row( int $id ): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
    }

    /**
     * Workflow rows joined with their live graph, and with the draft and newest version time when `$with_draft`.
     *
     * @param string $where SQL with placeholders for `$args`, on alias `w`.
     * @param array  $args
     * @param bool   $with_draft
     */
    private static function select( string $where, array $args, bool $with_draft ): array {
        global $wpdb;
        $workflows = self::table();
        $versions  = VersionStore::table();
        $columns   = 'w.*, l.graph AS live_graph';
        $joins     = "LEFT JOIN {$versions} l ON l.id = w.live_version_id";
        if ( $with_draft ) {
            $columns .= ", d.graph AS draft_graph, d.updated_at AS draft_updated_at, (SELECT MAX(v.updated_at) FROM {$versions} v WHERE v.workflow_id = w.id) AS last_version_at";
            $joins   .= " LEFT JOIN {$versions} d ON d.id = w.draft_version_id";
        }
        $sql = "SELECT {$columns} FROM {$workflows} w {$joins} {$where} ORDER BY w.updated_at DESC, w.id DESC";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $args ? $wpdb->prepare( $sql, ...$args ) : $sql, ARRAY_A );
    }

    private static function to_array( array $row ): array {
        $active     = 'active' === $row['status'];
        $updated_at = VersionStore::to_iso( $row['updated_at'] );
        return [
            'id'             => (string) $row['id'],
            'name'           => $row['name'],
            'status'         => $active ? 'active' : 'inactive',
            'graph'          => self::decode_graph( (string) $row['live_graph'] ),
            'draft'          => isset( $row['draft_graph'] ) ? self::decode_graph( $row['draft_graph'] ) : null,
            'updatedAt'      => $updated_at,
            'draftUpdatedAt' => isset( $row['draft_updated_at'] ) ? VersionStore::to_iso( $row['draft_updated_at'] ) : null,
            'lastVersionAt'  => isset( $row['last_version_at'] ) ? VersionStore::to_iso( $row['last_version_at'] ) : $updated_at,
            'everActivated'  => null !== $row['activated_at'],
            'activatedAt'    => $active && null !== $row['activated_at'] ? VersionStore::to_iso( $row['activated_at'] ) : null,
        ];
    }

    private static function trigger_id( array $graph ): string {
        $trigger = '';
        foreach ( $graph['nodes'] as $node ) {
            if ( Step::KIND_TRIGGER === $node['kind'] ) {
                $trigger = (string) $node['data']['block'];
            }
        }
        return $trigger;
    }

    private static function decode_graph( string $json ): array {
        $graph = json_decode( $json, true );
        if ( ! is_array( $graph ) || ! is_array( $graph['nodes'] ?? null ) || ! is_array( $graph['edges'] ?? null ) ) {
            return [
                'nodes' => [],
                'edges' => [],
            ];
        }
        // Stored configs go through their step's sanitize() on read, so the editor and the runner get
        // the shape today's step defines.
        foreach ( $graph['nodes'] as &$node ) {
            $step = StepRegistry::get( (string) ( $node['data']['block'] ?? '' ) );
            if ( $step ) {
                $node['data']['config'] = $step->sanitize( $node['data']['config'] ?? [] );
            }
        }
        unset( $node );
        return $graph;
    }
}
