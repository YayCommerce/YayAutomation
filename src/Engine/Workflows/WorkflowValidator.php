<?php
namespace YayAutomation\Engine\Workflows;

use YayAutomation\Engine\Blocks\Block;
use YayAutomation\Engine\Blocks\BlockRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Turns REST input into a storable workflow. Rejects malformed input first, then anything
 * `errors()` reports, whether the workflow is active or not.
 * `errors()` mirrors `validateWorkflow()` in apps/admin-page/src/routes/workflows/validation.ts.
 */
final class WorkflowValidator {

    public const STATUSES = [ 'inactive', 'active' ];

    private const BRANCHES = [ 'then', 'otherwise' ];

    /**
     * @param mixed      $input   Decoded JSON body: { name, status, graph }.
     * @param array|null $current Stored workflow when updating, null when creating.
     * @return array|\WP_Error [ 'name' => string, 'status' => string, 'graph' => array ]
     */
    public static function prepare( $input, ?array $current = null ) {
        if ( ! is_array( $input ) ) {
            return self::error( __( 'Invalid workflow.', 'yay-automation' ) );
        }

        $name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );

        $status = $input['status'] ?? 'inactive';
        if ( ! in_array( $status, self::STATUSES, true ) ) {
            return self::error( __( 'Invalid workflow status.', 'yay-automation' ) );
        }

        $graph = self::prepare_graph( $input['graph'] ?? null );
        if ( is_wp_error( $graph ) ) {
            return $graph;
        }

        $workflow = [
            'name'   => $name,
            'status' => $status,
            'graph'  => $graph,
        ];

        $errors = self::errors( $workflow );
        if ( $errors && ! self::only_deactivates( $workflow, $current ) ) {
            return new \WP_Error(
                'yay_automation_invalid_workflow',
                /* translators: %d: number of issues */
                sprintf( _n( 'Fix %d issue before saving.', 'Fix %d issues before saving.', count( $errors ), 'yay-automation' ), count( $errors ) ),
                [
                    'status' => 400,
                    'errors' => $errors,
                ]
            );
        }

        return $workflow;
    }

    /**
     * True when the save does nothing but turn an active workflow off. Allowed even with issues, so a
     * workflow that became invalid (e.g. a status from a plugin that's gone) can always be stopped.
     * Mirrors `onlyDeactivates()` in apps/admin-page/src/routes/workflows/validation.ts.
     *
     * @param array      $workflow Prepared input.
     * @param array|null $current  Stored workflow, null when creating.
     */
    private static function only_deactivates( array $workflow, ?array $current ): bool {
        return $current
            && 'active' === $current['status']
            && 'inactive' === $workflow['status']
            && $workflow['name'] === $current['name']
            // Loose ==: same keys and values, key order doesn't matter.
            && $workflow['graph'] == $current['graph']; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
    }

    /**
     * Problems that block saving. Codes match the TS `validateWorkflow()`:
     * name_required, no_action, unreachable (per node), invalid_config (per node).
     *
     * @param array $workflow Well-formed workflow (graph from `prepare_graph()`).
     * @return array[] [ [ 'code' => string, 'message' => string, 'nodeId'? => string ], … ]
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
            $errors[] = [
                'code'    => 'no_action',
                'message' => __( 'Add an action to this workflow', 'yay-automation' ),
            ];
        }

        $reachable = self::reachable( $workflow['graph'] );
        foreach ( $nodes as $node ) {
            if ( ! isset( $reachable[ $node['id'] ] ) ) {
                $errors[] = [
                    'nodeId'  => $node['id'],
                    'code'    => 'unreachable',
                    'message' => __( 'This step isn’t connected to the trigger.', 'yay-automation' ),
                ];
            }
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
                    : null === $handle );
            if ( ! $valid ) {
                return self::error( __( 'Invalid connection between steps.', 'yay-automation' ) );
            }

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
