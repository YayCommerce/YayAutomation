<?php
namespace YayMatik\Workflow\Definition;

use YayMatik\Workflow\Steps\Core\Step;
use YayMatik\Workflow\Steps\Core\StepRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * A workflow graph's shape: what a save accepts, and its one normalized form. Issues that only
 * block Activate (missing settings, unlinked steps) are the Validator's, not this class's.
 *
 * Shape: `{ schemaVersion, nodes, edges }`. Nodes are trigger / condition / action steps with ids;
 * the graph is a tree from the one trigger: one parent per step, one child per handle, a
 * condition's edges always name their branch (`then` / `otherwise`), branches never merge.
 *
 * `schemaVersion`: graphs saved before it existed have none and read as 1. `prepare()` always puts
 * it first, so an old stored graph and the same graph saved today compare equal. Stored graphs and
 * versions are never rewritten to add it.
 */
final class GraphSchema {

    /** The graph shape this code writes. */
    public const VERSION = 1;

    private const BRANCHES = [ 'then', 'otherwise' ];

    private const KINDS = [ Step::KIND_TRIGGER, Step::KIND_CONDITION, Step::KIND_ACTION ];

    /**
     * The normalized graph: fixed key order and types, configs sanitized by their steps, unknown
     * keys dropped. A 400 WP_Error for a malformed one.
     *
     * @param mixed $graph Raw { schemaVersion?, nodes, edges }.
     * @return array|\WP_Error
     */
    public static function prepare( $graph ) {
        if ( ! is_array( $graph ) || ! is_array( $graph['nodes'] ?? null ) || ! is_array( $graph['edges'] ?? null ) ) {
            return self::error( __( 'Invalid workflow graph.', 'yaymatik' ) );
        }
        $version = self::version( $graph );
        if ( null === $version ) {
            return self::error( __( 'Invalid workflow graph.', 'yaymatik' ) );
        }
        if ( $version > self::VERSION ) {
            return self::error( __( 'This workflow was saved by a newer version of YayMatik. Update the plugin to edit it.', 'yaymatik' ) );
        }

        $nodes    = [];
        $triggers = 0;
        foreach ( $graph['nodes'] as $raw ) {
            $id = is_array( $raw ) ? (string) ( $raw['id'] ?? '' ) : '';
            if ( ! self::is_valid_id( $id ) || isset( $nodes[ $id ] ) ) {
                return self::error( __( 'Invalid or duplicate step id.', 'yaymatik' ) );
            }

            $kind    = (string) ( $raw['kind'] ?? '' );
            $data    = is_array( $raw['data'] ?? null ) ? $raw['data'] : [];
            $step_id = $data['block'] ?? null;
            // A trigger or action may be added before its step is chosen; a condition has just one step.
            $step  = null === $step_id ? null : StepRegistry::get( (string) $step_id );
            $known = $step ? $step->kind() === $kind : ( null === $step_id && Step::KIND_CONDITION !== $kind );
            if ( ! in_array( $kind, self::KINDS, true ) || ! $known ) {
                return self::error( __( 'Unknown step type.', 'yaymatik' ), $id );
            }
            if ( Step::KIND_TRIGGER === $kind ) {
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
                    'block'  => $step ? $step->id() : null,
                    'config' => $step ? $step->sanitize( $data['config'] ?? [] ) : [],
                ],
            ];
        }//end foreach

        if ( 1 !== $triggers ) {
            return self::error( __( 'A workflow needs exactly one trigger.', 'yaymatik' ) );
        }

        $edges = [];
        // Each step has at most one parent, and each handle at most one child. Rejecting anything
        // else also rules out cycles among steps reached from the trigger.
        $has_parent = [];
        $used_slots = [];
        foreach ( $graph['edges'] as $raw ) {
            $id     = is_array( $raw ) ? (string) ( $raw['id'] ?? '' ) : '';
            $source = $nodes[ (string) ( $raw['source'] ?? '' ) ] ?? null;
            $target = $nodes[ (string) ( $raw['target'] ?? '' ) ] ?? null;
            $handle = $raw['sourceHandle'] ?? null;

            $valid = self::is_valid_id( $id ) && ! isset( $edges[ $id ] )
                && $source && $target && $source['id'] !== $target['id']
                && Step::KIND_TRIGGER !== $target['kind']
                // Only condition steps branch, and they always name the branch.
                && ( Step::KIND_CONDITION === $source['kind']
                    ? in_array( $handle, self::BRANCHES, true )
                    : null === $handle )
                && ! isset( $has_parent[ $target['id'] ] )
                && ! isset( $used_slots[ $source['id'] . '|' . $handle ] );
            if ( ! $valid ) {
                return self::error( __( 'Invalid connection between steps.', 'yaymatik' ) );
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
            'schemaVersion' => self::VERSION,
            'nodes'         => array_values( $nodes ),
            'edges'         => array_values( $edges ),
        ];
    }

    /**
     * A new workflow's graph: one trigger step, not chosen yet.
     */
    public static function blank(): array {
        return [
            'nodes' => [
                [
                    'id'       => 'trigger',
                    'kind'     => Step::KIND_TRIGGER,
                    'position' => [
                        'x' => 0,
                        'y' => 0,
                    ],
                    'data'     => [
                        'block'  => null,
                        'config' => [],
                    ],
                ],
            ],
            'edges' => [],
        ];
    }

    /**
     * True when both are the same graph. Both go through `prepare()` (fixed key order and types,
     * `schemaVersion` filled in), so strict === holds: unlike loose ==, '500' and '5e2' or '' and
     * null stay different, and a stored graph without `schemaVersion` equals itself saved today.
     *
     * @param array|null $a Graph, or null for none.
     * @param array|null $b Graph, or null for none.
     */
    public static function same( ?array $a, ?array $b ): bool {
        if ( null === $a || null === $b ) {
            return $a === $b;
        }
        $a = self::prepare( $a );
        $b = self::prepare( $b );
        return ! is_wp_error( $a ) && ! is_wp_error( $b ) && $a === $b;
    }

    /**
     * The graph's shape version: missing reads as 1. Null when set but not a whole number ≥ 1.
     *
     * @param array $graph Graph.
     */
    public static function version( array $graph ): ?int {
        if ( ! array_key_exists( 'schemaVersion', $graph ) ) {
            return 1;
        }
        $version = $graph['schemaVersion'];
        return is_int( $version ) && $version >= 1 ? $version : null;
    }

    /**
     * Step and edge ids: letters, digits, `_` and `-`.
     *
     * @param string $id Id.
     */
    public static function is_valid_id( string $id ): bool {
        return (bool) preg_match( '/^[A-Za-z0-9_-]{1,200}$/', $id );
    }

    /**
     * @param string      $message What's wrong.
     * @param string|null $node_id Step it is about.
     */
    private static function error( string $message, ?string $node_id = null ): \WP_Error {
        $data = [ 'status' => 400 ];
        if ( null !== $node_id ) {
            $data['nodeId'] = $node_id;
        }
        return new \WP_Error( 'yaymatik_invalid_workflow', $message, $data );
    }
}
