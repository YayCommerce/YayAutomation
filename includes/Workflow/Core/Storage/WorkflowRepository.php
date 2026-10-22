<?php
namespace YayMatik\Workflow\Core\Storage;

use YayMatik\Workflow\Core\Steps\Step;
use YayMatik\Workflow\Core\Steps\StepRegistry;

defined( 'ABSPATH' ) || exit;

/** Workflows over the private post type, input prepared by `Validator`; arrays match the TS `Workflow` type. */
final class WorkflowRepository {

    // GRAPH is the live graph, the only one the runner reads. DRAFT_GRAPH holds unapplied edits of a
    // workflow that has ever been turned on, kept across turning on and off. ACTIVATED_AT is the last
    // turn-on; it also tells "Inactive" (turned off) from "Draft" (never on).
    private const META_STATUS        = '_yaymatik_status';
    private const META_GRAPH         = '_yaymatik_graph';
    private const META_TRIGGER       = '_yaymatik_trigger';
    private const META_DRAFT_GRAPH   = '_yaymatik_draft_graph';
    private const META_DRAFT_UPDATED = '_yaymatik_draft_updated';
    private const META_ACTIVATED_AT  = '_yaymatik_activated_at';
    private const META_LIVE_VERSION  = '_yaymatik_live_version';
    private const META_DRAFT_VERSION = '_yaymatik_draft_version';

    public static function all(): array {
        $posts = get_posts(
            [
                'post_type'      => WorkflowPostType::NAME,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'modified',
                'order'          => 'DESC',
            ]
        );

        $last_version = VersionStore::last_updated( wp_list_pluck( $posts, 'ID' ) );
        $workflows    = array_map( fn( \WP_Post $post ) => self::to_array( $post, true, $last_version[ $post->ID ] ?? null ), $posts );
        // Newest "Last updated" first, the date the list shows. ISO dates in UTC sort as strings.
        usort( $workflows, fn( array $a, array $b ) => strcmp( $b['lastVersionAt'], $a['lastVersionAt'] ) );
        return $workflows;
    }

    public static function find( int $id ): ?array {
        $post = self::get_post( $id );
        return $post ? self::to_array( $post, true, VersionStore::last_updated( [ $id ] )[ $id ] ?? null ) : null;
    }

    /**
     * From the table, not the object cache: a run's slice must see a Deactivate from another request.
     *
     * @param int $id
     * @throws \RuntimeException When the query fails, so a database error never reads as "turned off".
     */
    public static function is_active( int $id ): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $status = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $id, self::META_STATUS ) );
        if ( null === $status && '' !== $wpdb->last_error ) {
            throw new \RuntimeException( 'Could not read the workflow status: ' . $wpdb->last_error );
        }
        return 'active' === $status;
    }

    /**
     * Version row holding the live graph, which a new run pins; 0 when none was saved.
     *
     * @param int $id
     */
    public static function live_version_id( int $id ): int {
        return (int) get_post_meta( $id, self::META_LIVE_VERSION, true );
    }

    /**
     * One page of versions, newest first, shaped like the TS `WorkflowVersion`.
     *
     * @param int $id
     * @param int $page 1-based.
     * @return array{items: array[], hasMore: bool}
     */
    public static function versions( int $id, int $page ): array {
        $workflow = self::find( $id );
        $live     = self::live_version_id( $id );
        $draft    = self::draft_version( $id );
        $rows     = VersionStore::page( $id, $page );
        $has_more = count( $rows ) > VersionStore::PER_PAGE;

        $items = array_map(
            function ( array $row ) use ( $workflow, $live, $draft ) {
                $row_id = (int) $row['id'];
                $status = null;
                // Never turned on: its only content is a draft, even though it's stored as the live graph.
                if ( $row_id === $draft || ( $row_id === $live && ! $workflow['everActivated'] ) ) {
                    $status = 'draft';
                } elseif ( $row_id === $live && 'active' === $workflow['status'] ) {
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

    public static function active_for_trigger( string $step_id ): array {
        $posts = get_posts(
            [
                'post_type'      => WorkflowPostType::NAME,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                'meta_query'     => [
                    [
                        'key'   => self::META_STATUS,
                        'value' => 'active',
                    ],
                    [
                        'key'   => self::META_TRIGGER,
                        'value' => $step_id,
                    ],
                ],
            ]
        );
        // The runner only ever uses the live graph.
        return array_map( fn( \WP_Post $post ) => self::to_array( $post, false ), $posts );
    }

    /**
     * New inactive workflow.
     *
     * @param string $name
     * @param array  $graph
     * @return array|\WP_Error
     */
    public static function create( string $name, array $graph ) {
        $id = wp_insert_post(
            [
                'post_type'   => WorkflowPostType::NAME,
                'post_status' => 'publish',
                // wp_insert_post() unslashes its input.
                'post_title'  => wp_slash( $name ),
            ],
            true
        );
        if ( is_wp_error( $id ) ) {
            return $id;
        }
        update_post_meta( $id, self::META_STATUS, 'inactive' );
        self::write_graph( $id, $graph );
        update_post_meta( $id, self::META_LIVE_VERSION, VersionStore::insert( $id, VersionStore::ACTION_CREATED, $graph ) );
        return self::find( $id );
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
        // The post update goes first, so a failure leaves nothing half-written.
        $result = self::touch( $id, null === $name ? [] : [ 'post_title' => wp_slash( $name ) ] );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( null !== $graph ) {
            // Only a workflow never turned on edits its live graph: no run pins that version, so it's updated in place.
            self::write_graph( $id, $graph );
            VersionStore::update( self::live_version_id( $id ), VersionStore::ACTION_EDITED, $graph );
        }
        return true;
    }

    /**
     * Sets or (null) clears the draft. Meta only, so `post_modified` stays; its one version row is updated in place.
     *
     * @param int        $id
     * @param array|null $graph
     */
    public static function save_draft( int $id, ?array $graph ): void {
        $version = self::draft_version( $id );
        if ( null === $graph ) {
            if ( $version ) {
                VersionStore::delete( $version );
            }
            self::clear_draft_meta( $id );
            return;
        }
        update_post_meta( $id, self::META_DRAFT_GRAPH, wp_slash( wp_json_encode( $graph ) ) );
        update_post_meta( $id, self::META_DRAFT_UPDATED, gmdate( 'c' ) );
        if ( $version ) {
            VersionStore::update( $version, VersionStore::ACTION_EDITED, $graph );
        } else {
            update_post_meta( $id, self::META_DRAFT_VERSION, VersionStore::insert( $id, VersionStore::ACTION_EDITED, $graph ) );
        }
    }

    /**
     * @param int $id
     * @return true|\WP_Error
     */
    public static function activate( int $id ) {
        $result = self::touch( $id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        update_post_meta( $id, self::META_STATUS, 'active' );
        update_post_meta( $id, self::META_ACTIVATED_AT, gmdate( 'c' ) );
        self::record_live( $id, VersionStore::ACTION_ACTIVATED );
        return true;
    }

    /**
     * Turns off; the draft is kept.
     *
     * @param int $id
     * @return true|\WP_Error
     */
    public static function deactivate( int $id ) {
        $result = self::touch( $id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        update_post_meta( $id, self::META_STATUS, 'inactive' );
        self::record_live( $id, VersionStore::ACTION_DEACTIVATED );
        return true;
    }

    /**
     * Draft → live graph; its version row becomes the live one.
     *
     * @param int   $id
     * @param array $draft
     * @return true|\WP_Error
     */
    public static function apply_draft( int $id, array $draft ) {
        $result = self::touch( $id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        self::write_graph( $id, $draft );
        $version = self::draft_version( $id );
        if ( $version ) {
            VersionStore::touch( $version );
            update_post_meta( $id, self::META_LIVE_VERSION, $version );
        }
        self::clear_draft_meta( $id );
        return true;
    }

    /**
     * Permanent delete, no trash. False when the workflow doesn't exist.
     *
     * @param int $id
     */
    public static function delete( int $id ): bool {
        if ( ! self::get_post( $id ) || ! wp_delete_post( $id, true ) ) {
            return false;
        }
        VersionStore::delete_for_workflow( $id );
        return true;
    }

    /**
     * New version row holding the live graph, which becomes the live version.
     *
     * @param int    $id
     * @param string $action VersionStore::ACTION_ACTIVATED or ACTION_DEACTIVATED.
     */
    private static function record_live( int $id, string $action ): void {
        $graph = self::decode_graph( (string) get_post_meta( $id, self::META_GRAPH, true ) );
        update_post_meta( $id, self::META_LIVE_VERSION, VersionStore::insert( $id, $action, $graph ) );
    }

    private static function draft_version( int $id ): int {
        return (int) get_post_meta( $id, self::META_DRAFT_VERSION, true );
    }

    private static function clear_draft_meta( int $id ): void {
        delete_post_meta( $id, self::META_DRAFT_GRAPH );
        delete_post_meta( $id, self::META_DRAFT_UPDATED );
        delete_post_meta( $id, self::META_DRAFT_VERSION );
    }

    private static function write_graph( int $id, array $graph ): void {
        $trigger = '';
        foreach ( $graph['nodes'] as $node ) {
            if ( Step::KIND_TRIGGER === $node['kind'] ) {
                $trigger = (string) $node['data']['block'];
            }
        }
        update_post_meta( $id, self::META_TRIGGER, $trigger );
        // update_post_meta() unslashes, which would eat the backslashes in JSON escapes.
        update_post_meta( $id, self::META_GRAPH, wp_slash( wp_json_encode( $graph ) ) );
    }

    /**
     * Updates the post, which also moves `post_modified` to now.
     *
     * @param int   $id
     * @param array $fields Extra post fields, slashed.
     * @return true|\WP_Error
     */
    private static function touch( int $id, array $fields = [] ) {
        $result = wp_update_post( [ 'ID' => $id ] + $fields, true );
        return is_wp_error( $result ) ? $result : true;
    }

    private static function get_post( int $id ): ?\WP_Post {
        $post = get_post( $id );
        return $post && WorkflowPostType::NAME === $post->post_type ? $post : null;
    }

    /**
     * @param \WP_Post    $post
     * @param bool        $with_draft   False skips reading the draft (`draft` is then null).
     * @param string|null $last_version Newest version time (ISO 8601); null → `updatedAt`.
     */
    private static function to_array( \WP_Post $post, bool $with_draft = true, ?string $last_version = null ): array {
        $graph         = self::decode_graph( (string) get_post_meta( $post->ID, self::META_GRAPH, true ) );
        $draft         = $with_draft ? get_post_meta( $post->ID, self::META_DRAFT_GRAPH, true ) : '';
        $draft_updated = $with_draft ? get_post_meta( $post->ID, self::META_DRAFT_UPDATED, true ) : '';
        $active        = 'active' === get_post_meta( $post->ID, self::META_STATUS, true );
        $activated_at  = get_post_meta( $post->ID, self::META_ACTIVATED_AT, true );
        $updated_at    = get_post_modified_time( 'c', true, $post );

        return [
            'id'             => (string) $post->ID,
            'name'           => $post->post_title,
            'status'         => $active ? 'active' : 'inactive',
            'graph'          => $graph,
            'draft'          => $draft ? self::decode_graph( (string) $draft ) : null,
            'updatedAt'      => $updated_at,
            'draftUpdatedAt' => $draft_updated ? $draft_updated : null,
            'lastVersionAt'  => $last_version ?? $updated_at,
            'everActivated'  => (bool) $activated_at,
            'activatedAt'    => $active && $activated_at ? (string) $activated_at : null,
        ];
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
