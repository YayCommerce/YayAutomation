<?php
namespace YayAutomation\Engine\Workflows;

use YayAutomation\Engine\Blocks\Block;

defined( 'ABSPATH' ) || exit;

/**
 * Workflow CRUD over the private post type. Returned arrays match the TS `Workflow` type.
 * Input must already be prepared by `WorkflowValidator::prepare()`.
 */
final class WorkflowRepository {

    private const META_STATUS  = '_yay_automation_status';
    private const META_GRAPH   = '_yay_automation_graph';
    private const META_TRIGGER = '_yay_automation_trigger';

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
        return array_map( [ self::class, 'to_array' ], $posts );
    }

    /**
     * @param array $workflow Prepared workflow.
     * @return array|\WP_Error
     */
    public static function create( array $workflow ) {
        $id = wp_insert_post(
            [
                'post_type'   => WorkflowPostType::NAME,
                'post_status' => 'publish',
                // wp_insert_post() unslashes its input.
                'post_title'  => wp_slash( $workflow['name'] ),
            ],
            true
        );
        if ( is_wp_error( $id ) ) {
            return $id;
        }
        self::save_meta( $id, $workflow );
        return self::find( $id );
    }

    /**
     * @param int   $id       Post id.
     * @param array $workflow Prepared workflow.
     * @return array|\WP_Error|null Null when the workflow doesn't exist.
     */
    public static function update( int $id, array $workflow ) {
        if ( ! self::get_post( $id ) ) {
            return null;
        }
        $result = wp_update_post(
            [
                'ID'         => $id,
                'post_title' => wp_slash( $workflow['name'] ),
            ],
            true
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        self::save_meta( $id, $workflow );
        return self::find( $id );
    }

    /**
     * Permanent delete, no trash. False when the workflow doesn't exist.
     *
     * @param int $id Post id.
     */
    public static function delete( int $id ): bool {
        return self::get_post( $id ) && wp_delete_post( $id, true );
    }

    private static function save_meta( int $id, array $workflow ) {
        $trigger = '';
        foreach ( $workflow['graph']['nodes'] as $node ) {
            if ( Block::KIND_TRIGGER === $node['kind'] ) {
                $trigger = $node['data']['block'];
            }
        }

        update_post_meta( $id, self::META_STATUS, $workflow['status'] );
        update_post_meta( $id, self::META_TRIGGER, $trigger );
        // update_post_meta() unslashes, which would eat the backslashes in JSON escapes.
        update_post_meta( $id, self::META_GRAPH, wp_slash( wp_json_encode( $workflow['graph'] ) ) );
    }

    private static function get_post( int $id ): ?\WP_Post {
        $post = get_post( $id );
        return $post && WorkflowPostType::NAME === $post->post_type ? $post : null;
    }

    private static function to_array( \WP_Post $post ): array {
        $graph = json_decode( (string) get_post_meta( $post->ID, self::META_GRAPH, true ), true );
        if ( ! is_array( $graph ) ) {
            $graph = [
                'nodes' => [],
                'edges' => [],
            ];
        }

        return [
            'id'        => (string) $post->ID,
            'name'      => $post->post_title,
            'status'    => 'active' === get_post_meta( $post->ID, self::META_STATUS, true ) ? 'active' : 'inactive',
            'graph'     => $graph,
            'updatedAt' => get_post_modified_time( 'c', true, $post ),
        ];
    }
}
