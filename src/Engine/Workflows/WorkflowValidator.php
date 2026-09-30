<?php
namespace YayAutomation\Engine\Workflows;

use YayAutomation\Engine\Blocks\Core\Block;
use YayAutomation\Engine\Blocks\Core\BlockRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Turns REST input into storable workflow parts. Saves only reject malformed input (one trigger,
 * known blocks, valid edges); issues from `errors()` are allowed there and only block turning a
 * workflow on or applying a draft.
 * `errors()` mirrors `validateWorkflow()` in apps/admin-page/src/routes/workflows/validation.ts.
 */
final class WorkflowValidator {

    private const BRANCHES = [ 'then', 'otherwise' ];

    /**
     * New workflow from the POST body. Always created inactive, whatever `status` says.
     *
     * @param mixed $input Decoded JSON body: { name, graph }.
     * @return array|\WP_Error [ 'name' => string, 'graph' => array ]
     */
    public static function prepare_new( $input ) {
        if ( ! is_array( $input ) ) {
            return self::error( __( 'Invalid workflow.', 'yay-automation' ) );
        }
        $graph = self::prepare_graph( $input['graph'] ?? null );
        if ( is_wp_error( $graph ) ) {
            return $graph;
        }
        return [
            'name'  => self::prepare_name( $input['name'] ?? '' ),
            'graph' => $graph,
        ];
    }

    /**
     * Fields present in a PUT body. Absent keys stay absent; `status` is ignored (it changes only
     * through activate / deactivate). A name that sanitizes to empty is rejected.
     *
     * @param mixed $input Decoded JSON body: { name?, graph? }.
     * @return array|\WP_Error [ 'name'? => string, 'graph'? => array ]
     */
    public static function prepare_changes( $input ) {
        if ( ! is_array( $input ) ) {
            return self::error( __( 'Invalid workflow.', 'yay-automation' ) );
        }
        $changes = [];
        if ( array_key_exists( 'name', $input ) ) {
            $changes['name'] = self::prepare_name( $input['name'] );
            // The name applies at once, also to an active workflow, which must stay free of issues.
            if ( '' === $changes['name'] ) {
                return self::issues_error(
                    [
                        [
                            'code'    => 'name_required',
                            'message' => __( 'Enter a workflow name.', 'yay-automation' ),
                        ],
                    ]
                );
            }
        }
        if ( array_key_exists( 'graph', $input ) ) {
            $graph = self::prepare_graph( $input['graph'] );
            if ( is_wp_error( $graph ) ) {
                return $graph;
            }
            $changes['graph'] = $graph;
        }
        return $changes;
    }

    /**
     * True when both are the same graph. Both go through `prepare_graph()` (fixed key order and
     * types), so strict === holds: unlike loose ==, '500' and '5e2' or '' and null stay different.
     *
     * @param array|null $a Graph, or null for none.
     * @param array|null $b Graph, or null for none.
     */
    public static function same_graph( ?array $a, ?array $b ): bool {
        if ( null === $a || null === $b ) {
            return $a === $b;
        }
        $a = self::prepare_graph( $a );
        $b = self::prepare_graph( $b );
        return ! is_wp_error( $a ) && ! is_wp_error( $b ) && $a === $b;
    }

    /**
     * 400 listing everything `errors()` found, for turning on or applying a draft.
     *
     * @param array[] $errors From `errors()`.
     */
    public static function issues_error( array $errors ): \WP_Error {
        return new \WP_Error(
            'yay_automation_invalid_workflow',
            /* translators: %d: number of issues */
            sprintf( _n( 'Fix %d issue first.', 'Fix %d issues first.', count( $errors ), 'yay-automation' ), count( $errors ) ),
            [
                'status' => 400,
                'errors' => $errors,
            ]
        );
    }

    /**
     * Empty is allowed here; `errors()` reports it as name_required.
     *
     * @param mixed $name Raw name.
     */
    private static function prepare_name( $name ): string {
        return sanitize_text_field( is_scalar( $name ) ? (string) $name : '' );
    }

    /**
     * Problems that block turning a workflow on or applying a draft. Codes match the TS `validateWorkflow()`: name_required,
     * no_action (on the trigger), unlinked (one issue, `nodeIds` = every step not reached from the
     * trigger), invalid_config (per node).
     *
     * @param array $workflow Well-formed workflow (graph from `prepare_graph()`).
     * @return array[] [ [ 'code' => string, 'message' => string, 'nodeId'? => string, 'nodeIds'? => string[] ], … ]
     */
    public static function errors( array $workflow ): array {
        $errors = [];
        $nodes  = $workflow['graph']['nodes'];

        if ( '' === $workflow['name'] ) {
            $errors[] = [
                'code'    => 'name_required',
                'message' => __( 'Enter a workflow name.', 'yay-automation' ),
            ];
        }
        if ( ! in_array( Block::KIND_ACTION, array_column( $nodes, 'kind' ), true ) ) {
            $error = [
                'code'    => 'no_action',
                'message' => __( 'Add an action to this workflow', 'yay-automation' ),
            ];
            foreach ( $nodes as $node ) {
                if ( Block::KIND_TRIGGER === $node['kind'] ) {
                    $error['nodeId'] = $node['id'];
                }
            }
            $errors[] = $error;
        }

        $reachable = self::reachable( $workflow['graph'] );
        $unlinked  = array_values( array_diff( array_column( $nodes, 'id' ), array_keys( $reachable ) ) );
        if ( $unlinked ) {
            $errors[] = [
                'code'    => 'unlinked',
                'nodeIds' => $unlinked,
                'message' => __( 'Remove or connect unlinked steps', 'yay-automation' ),
            ];
        }

        foreach ( $nodes as $node ) {
            $valid = BlockRegistry::get( $node['data']['block'] )->validate( $node['data']['config'] );
            if ( is_wp_error( $valid ) ) {
                $errors[] = [
                    'nodeId'  => $node['id'],
                    'code'    => 'invalid_config',
                    'message' => $valid->get_error_message(),
                ];
            }
        }
        return $errors;
    }

    /**
     * Node ids reachable from the trigger by following edges.
     *
     * @param array $graph Well-formed graph.
     */
    private static function reachable( array $graph ): array {
        $queue = [];
        foreach ( $graph['nodes'] as $node ) {
            if ( Block::KIND_TRIGGER === $node['kind'] ) {
                $queue[] = $node['id'];
            }
        }
        $seen = [];
        while ( $queue ) {
            $id = array_shift( $queue );
            if ( isset( $seen[ $id ] ) ) {
                continue;
            }
            $seen[ $id ] = true;
            foreach ( $graph['edges'] as $edge ) {
                if ( $edge['source'] === $id ) {
                    $queue[] = $edge['target'];
                }
            }
        }
        return $seen;
    }

    /**
     * @param mixed $graph Raw { nodes, edges }.
     * @return array|\WP_Error
     */
    private static function prepare_graph( $graph ) {
        if ( ! is_array( $graph ) || ! is_array( $graph['nodes'] ?? null ) || ! is_array( $graph['edges'] ?? null ) ) {
            return self::error( __( 'Invalid workflow graph.', 'yay-automation' ) );
        }

        $nodes    = [];
        $triggers = 0;
        foreach ( $graph['nodes'] as $raw ) {
            $id = is_array( $raw ) ? (string) ( $raw['id'] ?? '' ) : '';
            if ( ! self::is_valid_id( $id ) || isset( $nodes[ $id ] ) ) {
                return self::error( __( 'Invalid or duplicate step id.', 'yay-automation' ) );
            }

            $kind  = (string) ( $raw['kind'] ?? '' );
            $data  = is_array( $raw['data'] ?? null ) ? $raw['data'] : [];
            $block = BlockRegistry::get( (string) ( $data['block'] ?? '' ) );
            if ( ! $block || $block->kind() !== $kind ) {
                return self::error( __( 'Unknown step type.', 'yay-automation' ), $id );
            }
            if ( Block::KIND_TRIGGER === $kind ) {
                ++$triggers;
            }

            $position     = is_array( $raw['position'] ?? null ) ? $raw['position'] : [];
            $nodes[ $id ] = [
                'id'       => $id,
                'kind'     => $kind,
                'position' => [
                    'x' => is_numeric( $position['x'] ?? null ) ? (float) $position['x'] : 0.0,
                    'y' => is_numeric( $position['y'] ?? null ) ? (float) $position['y'] : 0.0,
                ],
                'data'     => [
                    'title'  => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
                    'block'  => $block->id(),
                    'config' => $block->sanitize( $data['config'] ?? [] ),
                ],
            ];
        }//end foreach

        if ( 1 !== $triggers ) {
            return self::error( __( 'A workflow needs exactly one trigger.', 'yay-automation' ) );
        }

        $edges = [];
        // The editor builds a tree: each step has at most one parent, and each handle at most one
        // child. Rejecting anything else also rules out cycles among steps reached from the trigger.
        $has_parent = [];
        $used_slots = [];
        foreach ( $graph['edges'] as $raw ) {
            $id     = is_array( $raw ) ? (string) ( $raw['id'] ?? '' ) : '';
            $source = $nodes[ (string) ( $raw['source'] ?? '' ) ] ?? null;
            $target = $nodes[ (string) ( $raw['target'] ?? '' ) ] ?? null;
            $handle = $raw['sourceHandle'] ?? null;

            $valid = self::is_valid_id( $id ) && ! isset( $edges[ $id ] )
                && $source && $target && $source['id'] !== $target['id']
                && Block::KIND_TRIGGER !== $target['kind']
                // Only condition steps branch, and they always name the branch.
                && ( Block::KIND_CONDITION === $source['kind']
                    ? in_array( $handle, self::BRANCHES, true )
                    : null === $handle )
                && ! isset( $has_parent[ $target['id'] ] )
                && ! isset( $used_slots[ $source['id'] . '|' . $handle ] );
            if ( ! $valid ) {
                return self::error( __( 'Invalid connection between steps.', 'yay-automation' ) );
            }

            $has_parent[ $target['id'] ]                 = true;
            $used_slots[ $source['id'] . '|' . $handle ] = true;

            $edges[ $id ] = [
                'id'     => $id,
                'source' => $source['id'],
                'target' => $target['id'],
            ];
            if ( null !== $handle ) {
                $edges[ $id ]['sourceHandle'] = $handle;
            }
        }//end foreach

        return [
            'nodes' => array_values( $nodes ),
            'edges' => array_values( $edges ),
        ];
    }

    private static function is_valid_id( string $id ): bool {
        return (bool) preg_match( '/^[A-Za-z0-9_-]{1,200}$/', $id );
    }

    private static function error( string $message, ?string $node_id = null ): \WP_Error {
        $data = [ 'status' => 400 ];
        if ( null !== $node_id ) {
            $data['nodeId'] = $node_id;
        }
        return new \WP_Error( 'yay_automation_invalid_workflow', $message, $data );
    }
}
