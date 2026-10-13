<?php
namespace YayAutomation\Workflow\Storage;

use YayAutomation\Workflow\Steps\Core\Step;
use YayAutomation\Workflow\Steps\Core\StepRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Workflow storage over the private post type. Returned arrays match the TS `Workflow` type.
 * Input must already be prepared by `Validator`.
 *
 * - `_yay_automation_graph` is the live graph: the only one the runner reads.
 * - `_yay_automation_draft_graph` holds unapplied edits of a workflow that has ever been turned on
 *   (active or not). It's kept across turning on and off.
 * - `post_modified` moves with the live graph, status and name only; draft saves write meta alone,
 *   so they don't touch it.
 * - Every graph change is also kept as a version (`VersionStore`). `_yay_automation_live_version`
 *   and `_yay_automation_draft_version` point at the rows holding the live graph and the draft.
 *   "Last updated" (`lastVersionAt`) is the newest row's time.
 */
final class WorkflowRepository {

    private const META_STATUS        = '_yay_automation_status';
    private const META_GRAPH         = '_yay_automation_graph';
    private const META_TRIGGER       = '_yay_automation_trigger';
    private const META_DRAFT_GRAPH   = '_yay_automation_draft_graph';
    private const META_DRAFT_UPDATED = '_yay_automation_draft_updated';
    // When the workflow was last turned on. Set on every activate; deactivate only adds it when missing,
    // so it also tells "Inactive" (turned off) from "Draft" (never on).
    private const META_ACTIVATED_AT  = '_yay_automation_activated_at';
    private const META_LIVE_VERSION  = '_yay_automation_live_version';
    private const META_DRAFT_VERSION = '_yay_automation_draft_version';

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
     * False for a workflow saved before versions existed, until `seed_versions()` (or any write) runs.
     *
     * @param int $id Post id.
     */
    public static function has_versions( int $id ): bool {
        return (bool) self::live_version_id( $id );
    }

    /**
     * Version row holding the live graph, which a new run pins; 0 for a workflow saved before versions existed.
     *
     * @param int $id Post id.
     */
    public static function live_version_id( int $id ): int {
        return (int) get_post_meta( $id, self::META_LIVE_VERSION, true );
    }

    /**
     * Seeds the versions of a workflow saved before versions existed; no-op otherwise.
     *
     * @param int $id Post id of an existing workflow.
     */
    public static function seed_versions( int $id ): void {
        self::ensure_versions( $id );
    }

    /**
     * One page of versions, newest first, shaped like the TS `WorkflowVersion`.
     *
     * @param int $id   Post id of an existing workflow.
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

    /**
     * Active workflows whose trigger is the given step.
     *
     * @param string $step_id Trigger step id.
     */
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
     * @param string $name  Prepared name.
     * @param array  $graph Prepared graph.
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
     * Changes the live workflow: name and/or graph. One post update, done first, so a failure
     * leaves nothing half-written. A new graph replaces the live version's snapshot in place: only
     * workflows never turned on edit the live graph directly.
     *
     * @param int         $id    Post id of an existing workflow.
     * @param string|null $name  Prepared name, or null to keep it.
     * @param array|null  $graph Prepared graph, or null to keep it.
     * @return true|\WP_Error
     */
    public static function update_live( int $id, ?string $name, ?array $graph ) {
        $result = self::touch( $id, null === $name ? [] : [ 'post_title' => wp_slash( $name ) ] );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( null !== $graph ) {
            self::ensure_versions( $id );
            self::write_graph( $id, $graph );
            VersionStore::update( self::live_version_id( $id ), VersionStore::ACTION_EDITED, $graph );
        }
        return true;
    }

    /**
     * Sets or (null) clears the draft. Meta only: the live workflow and `post_modified` are untouched.
     * The draft has one version row, updated in place on every save and deleted when cleared.
     *
     * @param int        $id    Post id of an existing workflow.
     * @param array|null $graph Prepared graph, or null to clear.
     */
    public static function save_draft( int $id, ?array $graph ): void {
        self::ensure_versions( $id );
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
     * @param int $id Post id of an existing workflow.
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
     * Turns off. The draft, if any, is kept.
     *
     * @param int $id Post id of an existing workflow.
     * @return true|\WP_Error
     */
    public static function deactivate( int $id ) {
        $result = self::touch( $id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        // Workflows turned on before this meta existed get it here, so they turn "Inactive", not "Draft".
        self::mark_activated( $id );
        update_post_meta( $id, self::META_STATUS, 'inactive' );
        self::record_live( $id, VersionStore::ACTION_DEACTIVATED );
        return true;
    }

    /**
     * Draft → live graph; the draft is cleared. Its version row becomes the live one.
     *
     * @param int   $id    Post id of an existing workflow.
     * @param array $draft The workflow's draft.
     * @return true|\WP_Error
     */
    public static function apply_draft( int $id, array $draft ) {
        $result = self::touch( $id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        self::ensure_versions( $id );
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
     * @param int $id Post id.
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
     * @param int    $id     Post id of an existing workflow.
     * @param string $action VersionStore::ACTION_ACTIVATED or ACTION_DEACTIVATED.
     */
    private static function record_live( int $id, string $action ): void {
        self::ensure_versions( $id );
        $graph = self::decode_graph( (string) get_post_meta( $id, self::META_GRAPH, true ) );
        update_post_meta( $id, self::META_LIVE_VERSION, VersionStore::insert( $id, $action, $graph ) );
    }

    /**
     * Workflows saved before versions existed have none: seed a "created" row from the post (author,
     * date, live graph), plus a row for the draft if there is one. Call before recording anything.
     *
     * @param int $id Post id of an existing workflow.
     */
    private static function ensure_versions( int $id ): void {
        if ( self::live_version_id( $id ) ) {
            return;
        }
        $post = self::get_post( $id );
        if ( ! $post ) {
            return;
        }
        $author = (int) $post->post_author;
        $graph  = self::decode_graph( (string) get_post_meta( $id, self::META_GRAPH, true ) );
        update_post_meta( $id, self::META_LIVE_VERSION, VersionStore::insert( $id, VersionStore::ACTION_CREATED, $graph, $author, $post->post_date_gmt ) );

        $draft = (string) get_post_meta( $id, self::META_DRAFT_GRAPH, true );
        if ( $draft ) {
            $updated = (string) get_post_meta( $id, self::META_DRAFT_UPDATED, true );
            $time    = $updated ? gmdate( 'Y-m-d H:i:s', (int) strtotime( $updated ) ) : null;
            update_post_meta( $id, self::META_DRAFT_VERSION, VersionStore::insert( $id, VersionStore::ACTION_EDITED, self::decode_graph( $draft ), $author, $time ) );
        }
    }

    private static function draft_version( int $id ): int {
        return (int) get_post_meta( $id, self::META_DRAFT_VERSION, true );
    }

    private static function clear_draft_meta( int $id ): void {
        delete_post_meta( $id, self::META_DRAFT_GRAPH );
        delete_post_meta( $id, self::META_DRAFT_UPDATED );
        delete_post_meta( $id, self::META_DRAFT_VERSION );
    }

    private static function mark_activated( int $id ): void {
        if ( ! get_post_meta( $id, self::META_ACTIVATED_AT, true ) ) {
            update_post_meta( $id, self::META_ACTIVATED_AT, gmdate( 'c' ) );
        }
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
     * @param int   $id     Post id.
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
     * @param \WP_Post    $post         Workflow post.
     * @param bool        $with_draft   False skips reading the draft (`draft` is then always null).
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
            // Active without the meta: turned on before the meta existed; still counts.
            'everActivated'  => $active || (bool) $activated_at,
            // Null while inactive, and for a workflow turned on before the meta existed.
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
        // Re-sanitizing brings configs saved in an older shape up to date, so the editor and the
        // runner only ever see the current one.
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
