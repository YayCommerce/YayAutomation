<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Workflow\Core\Steps\Step;
use YayMatik\Workflow\Core\Steps\StepRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * An extension template's chain of steps as a workflow graph, laid out like the editor's.
 *
 * @internal
 */
final class TemplateBuilder {

    /** Same grid as `ROW_GAP` / `BRANCH_OFFSET` in apps/admin-page/src/routes/workflows/model/graph.ts. */
    public const ROW_GAP = 170;

    public const BRANCH_OFFSET = 200;

    private const NODE_ID = '/^[a-z][a-z0-9_-]{0,63}$/';

    /**
     * The graph, or why the chain can't be one.
     *
     * @param array $steps `[ [ id, step, config?, then?, otherwise? ], … ]`, the trigger first.
     * @return array|string
     */
    public static function graph( array $steps ) {
        $graph = [
            'nodes' => [],
            'edges' => [],
        ];
        $error = self::add( $graph, array_values( $steps ), null, null, 0, 0 );
        return $error ?? $graph;
    }

    /**
     * Adds a list of steps below `$above`; returns why it can't, or null.
     *
     * @param array       $graph
     * @param array       $steps
     * @param string|null $above  Node id the list hangs from; null for the top list, which starts with the trigger.
     * @param string|null $branch `then` / `otherwise` for a condition's first child.
     * @param int         $x
     * @param int         $y
     */
    private static function add( array &$graph, array $steps, ?string $above, ?string $branch, int $x, int $y ): ?string {
        foreach ( $steps as $i => $item ) {
            $id    = is_array( $item ) && is_string( $item['id'] ?? null ) ? $item['id'] : '';
            $block = is_array( $item ) && is_string( $item['step'] ?? null ) ? $item['step'] : '';
            if ( ! preg_match( self::NODE_ID, $id ) || in_array( $id, array_column( $graph['nodes'], 'id' ), true ) ) {
                return "Each step needs its own \"id\" (lowercase letters, digits, _ or -); \"{$id}\" isn't one.";
            }
            if ( isset( $item['config'] ) && ! is_array( $item['config'] ) ) {
                return "Step \"{$id}\": \"config\" must be an array.";
            }
            $step = '' === $block ? null : StepRegistry::get( $block );
            $kind = $step ? $step->kind() : null;
            if ( null === $kind ) {
                return "Step \"{$id}\": unknown step \"{$block}\".";
            }
            if ( ( null === $above ) !== ( Step::KIND_TRIGGER === $kind ) ) {
                return null === $above ? 'The first step must be a trigger.' : "Step \"{$id}\": a trigger can only be the first step.";
            }
            $is_condition = Step::KIND_CONDITION === $kind;
            if ( ! $is_condition && ( isset( $item['then'] ) || isset( $item['otherwise'] ) ) ) {
                return "Step \"{$id}\": only a condition has \"then\" / \"otherwise\".";
            }
            if ( $is_condition && $i < count( $steps ) - 1 ) {
                return "Step \"{$id}\": a condition ends its list; put the steps after it under \"then\" / \"otherwise\".";
            }

            $graph['nodes'][] = [
                'id'       => $id,
                'kind'     => $kind,
                'position' => [
                    'x' => $x,
                    'y' => $y,
                ],
                'data'     => [
                    'block'  => $block,
                    'config' => $item['config'] ?? [],
                ],
            ];
            if ( null !== $above ) {
                $graph['edges'][] = [
                    'id'     => "e-{$above}-{$id}",
                    'source' => $above,
                    'target' => $id,
                ] + ( null === $branch ? [] : [ 'sourceHandle' => $branch ] );
            }
            $above  = $id;
            $branch = null;
            $y     += self::ROW_GAP;

            if ( $is_condition ) {
                foreach ( [
                    'then'      => -self::BRANCH_OFFSET,
                    'otherwise' => self::BRANCH_OFFSET,
                ] as $handle => $offset ) {
                    if ( ! isset( $item[ $handle ] ) ) {
                        continue;
                    }
                    if ( ! is_array( $item[ $handle ] ) ) {
                        return "Step \"{$id}\": \"{$handle}\" must be a list of steps.";
                    }
                    $error = self::add( $graph, array_values( $item[ $handle ] ), $id, $handle, $x + $offset, $y );
                    if ( null !== $error ) {
                        return $error;
                    }
                }
            }
        }
        return null;
    }
}
