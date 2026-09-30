<?php
namespace YayAutomation\Engine\Workflows;

use YayAutomation\Engine\Blocks\Core\Block;
use YayAutomation\Engine\Blocks\Core\BlockRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Workflow storage over the private post type. Returned arrays match the TS `Workflow` type.
 * Input must already be prepared by `WorkflowValidator`.
 *
 * - `_yay_automation_graph` is the live graph: the only one the runner reads.
 * - `_yay_automation_draft_graph` holds unapplied edits of an active workflow.
 * - `post_modified` ("Last updated") moves with the live graph, status and name only; draft saves
 *   write meta alone, so they don't touch it.
 */
final class WorkflowRepository {

    private const META_STATUS        = '_yay_automation_status';
    private const META_GRAPH         = '_yay_automation_graph';
    private const META_TRIGGER       = '_yay_automation_trigger';
    private const META_DRAFT_GRAPH   = '_yay_automation_draft_graph';
    private const META_DRAFT_UPDATED = '_yay_automation_draft_updated';

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
        return array_map( [ self::class, 'to_array' ], $posts );
    }

    public static function find( int $id ): ?array {
        $post = self::get_post( $id );
        return $post ? self::to_array( $post ) : null;
    }

    /**
     * Active workflows whose trigger step uses the given block.
     *
     * @param string $block_id Trigger block id.
     */
    public static function active_for_trigger( string $block_id ): array {
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
                        'value' => $block_id,
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
        return self::find( $id );
    }

    /**
     * Changes the live workflow: name and/or graph. One post update, done first, so a failure
     * leaves nothing half-written.
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
            self::write_graph( $id, $graph );
        }
        return true;
    }

    /**
     * Sets or (null) clears the draft. Meta only: the live workflow and `post_modified` are untouched.
     *
     * @param int        $id    Post id of an existing workflow.
     * @param array|null $graph Prepared graph, or null to clear.
     */
    public static function save_draft( int $id, ?array $graph ): void {
        if ( null === $graph ) {
            delete_post_meta( $id, self::META_DRAFT_GRAPH );
            delete_post_meta( $id, self::META_DRAFT_UPDATED );
            return;
        }
        update_post_meta( $id, self::META_DRAFT_GRAPH, wp_slash( wp_json_encode( $graph ) ) );
        update_post_meta( $id, self::META_DRAFT_UPDATED, gmdate( 'c' ) );
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
        return true;
    }

    /**
     * Turns off. A draft becomes the live graph in the same step, so no edit is lost.
     *
     * @param int        $id    Post id of an existing workflow.
     * @param array|null $draft The workflow's draft, if any.
     * @return true|\WP_Error
     */
    public static function deactivate( int $id, ?array $draft ) {
        $result = self::touch( $id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        update_post_meta( $id, self::META_STATUS, 'inactive' );
        if ( null !== $draft ) {
            self::write_graph( $id, $draft );
        }
        self::save_draft( $id, null );
        return true;
    }

    /**
     * Draft → live graph; the draft is cleared.
     *
     * @param int   $id    Post id of an existing workflow.
     * @param array $draft The workflow's draft.
     * @return true|\WP_Error
     */
    public static function apply_draft( int $id, array $draft ) {
        $result = self::update_live( $id, null, $draft );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        self::save_draft( $id, null );
        return true;
    }

    /**
     * Permanent delete, no trash. False when the workflow doesn't exist.
     *
     * @param int $id Post id.
     */
    public static function delete( int $id ): bool {
        return self::get_post( $id ) && wp_delete_post( $id, true );
    }

    private static function write_graph( int $id, array $graph ): void {
        $trigger = '';
        foreach ( $graph['nodes'] as $node ) {
            if ( Block::KIND_TRIGGER === $node['kind'] ) {
                $trigger = $node['data']['block'];
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
     * @param \WP_Post $post       Workflow post.
     * @param bool     $with_draft False skips reading the draft (`draft` is then always null).
     */
    private static function to_array( \WP_Post $post, bool $with_draft = true ): array {
        $graph         = self::decode_graph( (string) get_post_meta( $post->ID, self::META_GRAPH, true ) );
        $draft         = $with_draft ? get_post_meta( $post->ID, self::META_DRAFT_GRAPH, true ) : '';
        $draft_updated = $with_draft ? get_post_meta( $post->ID, self::META_DRAFT_UPDATED, true ) : '';

        return [
            'id'             => (string) $post->ID,
            'name'           => $post->post_title,
            'status'         => 'active' === get_post_meta( $post->ID, self::META_STATUS, true ) ? 'active' : 'inactive',
            'graph'          => $graph,
            'draft'          => $draft ? self::decode_graph( (string) $draft ) : null,
            'updatedAt'      => get_post_modified_time( 'c', true, $post ),
            'draftUpdatedAt' => $draft_updated ? $draft_updated : null,
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
        // Re-sanitizing upgrades configs saved in an older shape (e.g. a single status string),
        // so the editor and the runner only ever see the current one.
        foreach ( $graph['nodes'] as &$node ) {
            $block = BlockRegistry::get( (string) ( $node['data']['block'] ?? '' ) );
            if ( $block ) {
                $node['data']['config'] = $block->sanitize( $node['data']['config'] ?? [] );
            }
        }
        unset( $node );
        return $graph;
    }
}
