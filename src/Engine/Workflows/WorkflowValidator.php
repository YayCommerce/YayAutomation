<?php
namespace YayAutomation\Engine\Workflows;

use YayAutomation\Engine\Blocks\Block;
use YayAutomation\Engine\Blocks\BlockRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Turns REST input into a storable workflow. Every workflow must be well-formed;
 * an active one must also have every step configured.
 */
final class WorkflowValidator {

    public const STATUSES = [ 'inactive', 'active' ];

    private const BRANCHES = [ 'then', 'otherwise' ];

    /**
     * @param mixed $input Decoded JSON body: { name, status, graph }.
     * @return array|\WP_Error [ 'name' => string, 'status' => string, 'graph' => array ]
     */
    public static function prepare( $input ) {
        if ( ! is_array( $input ) ) {
            return self::error( __( 'Invalid workflow.', 'yay-automation' ) );
        }

        $name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
        if ( '' === $name ) {
            return self::error( __( 'Enter a workflow name.', 'yay-automation' ) );
        }

        $status = $input['status'] ?? 'inactive';
        if ( ! in_array( $status, self::STATUSES, true ) ) {
            return self::error( __( 'Invalid workflow status.', 'yay-automation' ) );
        }

        $graph = self::prepare_graph( $input['graph'] ?? null );
        if ( is_wp_error( $graph ) ) {
            return $graph;
        }

        if ( 'active' === $status ) {
            foreach ( $graph['nodes'] as $node ) {
                $valid = BlockRegistry::get( $node['data']['block'] )->validate( $node['data']['config'] );
                if ( is_wp_error( $valid ) ) {
                    return self::error(
                        /* translators: 1: step title, 2: validation message */
                        sprintf( __( 'Configure "%1$s" before activating: %2$s', 'yay-automation' ), $node['data']['title'], $valid->get_error_message() ),
                        $node['id']
                    );
                }
            }
        }

        return [
            'name'   => $name,
            'status' => $status,
            'graph'  => $graph,
        ];
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
