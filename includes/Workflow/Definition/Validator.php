<?php
namespace YayMatik\Workflow\Definition;

use YayMatik\Workflow\Data\DataItem;
use YayMatik\Workflow\Data\DataRegistry;
use YayMatik\Workflow\Data\Template;
use YayMatik\Workflow\Data\Type;
use YayMatik\Workflow\Steps\Core\Field;
use YayMatik\Workflow\Steps\Core\Step;
use YayMatik\Workflow\Steps\Core\StepRegistry;
use YayMatik\Utils\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Turns REST input into storable workflow parts, and finds a workflow's issues. Saves only reject
 * malformed input (graph shape: `GraphSchema`); issues from `errors()` are allowed there and only
 * block turning a workflow on or applying a draft.
 *
 * What a step can use: the trigger's data, the outputs of the steps above it (`steps.<id>.<key>`;
 * both branches of a condition see its outputs) and the always-available items. Merge tags in a
 * step's text fields are checked against that, deep paths (`order.customer.first_name`) through
 * the data catalog.
 *
 * `errors()` mirrors `validateWorkflow()` in apps/admin-page/src/routes/workflows/model/validation.ts.
 */
final class Validator {

    /**
     * New workflow from the POST body. Always created inactive, whatever `status` says. Without a
     * graph it's a blank "New workflow": one trigger step, not chosen yet.
     *
     * @param mixed $input Decoded JSON body: { name?, graph? }.
     * @return array|\WP_Error [ 'name' => string, 'graph' => array ]
     */
    public static function prepare_new( $input ) {
        $input = is_array( $input ) ? $input : [];
        $graph = GraphSchema::prepare( $input['graph'] ?? GraphSchema::blank() );
        if ( is_wp_error( $graph ) ) {
            return $graph;
        }
        return [
            'name'  => array_key_exists( 'name', $input ) ? self::prepare_name( $input['name'] ) : __( 'New workflow', 'yaymatik' ),
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
            return self::error( __( 'Invalid workflow.', 'yaymatik' ) );
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
                            'message' => Messages::get( 'nameRequired' ),
                        ],
                    ],
                    'apply'
                );
            }
        }
        if ( array_key_exists( 'graph', $input ) ) {
            $graph = GraphSchema::prepare( $input['graph'] );
            if ( is_wp_error( $graph ) ) {
                return $graph;
            }
            $changes['graph'] = $graph;
        }
        return $changes;
    }

    /**
     * 400 listing everything `errors()` found, for turning on (`activate`) or applying a draft (`apply`).
     *
     * @param array[] $errors From `errors()`.
     * @param string  $action `activate` or `apply`.
     */
    public static function issues_error( array $errors, string $action ): \WP_Error {
        $count   = count( $errors );
        $message = 'apply' === $action
            /* translators: %d: number of issues */
            ? _n( 'Fix %d issue before you update this workflow.', 'Fix %d issues before you update this workflow.', $count, 'yaymatik' )
            /* translators: %d: number of issues */
            : _n( 'Fix %d issue before you activate this workflow.', 'Fix %d issues before you activate this workflow.', $count, 'yaymatik' );
        return new \WP_Error(
            'yaymatik_invalid_workflow',
            sprintf( $message, $count ),
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
     * Issues of a stored workflow's live graph and of its draft (null without one), for REST responses.
     *
     * @param array $workflow Stored workflow: name, graph, draft.
     * @return array [ 'live' => issue[], 'draft' => issue[]|null ]
     */
    public static function issues( array $workflow ): array {
        $draft = $workflow['draft'] ?? null;
        return [
            'live'  => self::errors(
                [
                    'name'  => $workflow['name'],
                    'graph' => $workflow['graph'],
                ]
            ),
            'draft' => null === $draft ? null : self::errors(
                [
                    'name'  => $workflow['name'],
                    'graph' => $draft,
                ]
            ),
        ];
    }

    /**
     * `issues()` for reading workflows (the list, opening one): never throws, so a workflow that
     * can't be checked doesn't break the list and can still be opened and fixed. It gets no issues
     * and the error is logged; Activate and apply still run the full check.
     *
     * @param array $workflow Stored workflow: id, name, graph, draft.
     * @return array [ 'live' => issue[], 'draft' => issue[] ]
     */
    public static function list_issues( array $workflow ): array {
        try {
            return self::issues( $workflow );
        } catch ( \Throwable $e ) {
            Logger::error( 'Could not check workflow ' . ( $workflow['id'] ?? '?' ) . ': ' . $e->getMessage(), [ 'workflow_id' => $workflow['id'] ?? null ] );
            return [
                'live'  => [],
                'draft' => [],
            ];
        }
    }

    /**
     * Problems that block turning a workflow on or applying a draft. Codes match the TS `validateWorkflow()`:
     * name_required, no_trigger (trigger not chosen), no_action (both workflow-level), unlinked (one issue,
     * `nodeIds` = every step not reached from the trigger), and per step: no_block (action not chosen),
     * invalid_config (settings, unknown merge tag or filter), missing_data (uses data the trigger
     * doesn't provide, or a step that isn't above it). A step's issue names its `field` when it has one.
     *
     * @param array $workflow Well-formed workflow (graph from `GraphSchema::prepare()`).
     * @return array[] [ [ 'code' => string, 'message' => string, 'field'? => string, 'nodeId'? => string, 'nodeIds'? => string[] ], … ]
     */
    public static function errors( array $workflow ): array {
        $errors       = [];
        $nodes        = $workflow['graph']['nodes'];
        $trigger_step = self::trigger_step( $nodes );

        if ( '' === $workflow['name'] ) {
            $errors[] = [
                'code'    => 'name_required',
                'message' => Messages::get( 'nameRequired' ),
            ];
        }
        // Workflow-level: there is no step to point at.
        if ( ! $trigger_step ) {
            $errors[] = [
                'code'    => 'no_trigger',
                'message' => Messages::get( 'noTrigger' ),
            ];
        }
        if ( ! in_array( Step::KIND_ACTION, array_column( $nodes, 'kind' ), true ) ) {
            $errors[] = [
                'code'    => 'no_action',
                'message' => Messages::get( 'noAction' ),
            ];
        }

        $reachable = self::reachable( $workflow['graph'] );
        $unlinked  = array_values( array_diff( array_column( $nodes, 'id' ), array_keys( $reachable ) ) );
        if ( $unlinked ) {
            $errors[] = [
                'code'    => 'unlinked',
                'nodeIds' => $unlinked,
                'message' => Messages::get( 'unlinked' ),
            ];
        }

        // Data items the steps can read. Unknown until a trigger is chosen; no_trigger covers that case.
        $available = $trigger_step ? array_merge( $trigger_step->provides(), DataRegistry::ALWAYS ) : null;
        $by_id     = array_column( $nodes, null, 'id' );
        $parents   = array_column( $workflow['graph']['edges'], 'source', 'target' );
        foreach ( $nodes as $node ) {
            $error = self::step_error( $node, $available, $by_id, self::ancestors( $node['id'], $parents ) );
            if ( $error ) {
                $errors[] = $error + [ 'nodeId' => $node['id'] ];
            }
        }
        return $errors;
    }

    /**
     * Variables one step can use, in the order "Insert variable" lists them: the trigger's data
     * items and the always-available ones, each item's fields then its relations to one entity
     * (`order.customer.first_name`; the relation itself has no text), then the outputs of the steps
     * above it, top to bottom (`steps.<id>.<key>`). Without a trigger only the always-available
     * items. Cases in tests/fixtures/variables.json, shared with the TS `availableVariables()`.
     *
     * @param array  $graph   Well-formed graph (from `GraphSchema::prepare()`).
     * @param string $node_id The step.
     * @return array[] [ [ 'key' => string, 'label' => string, 'type' => string ], … ], `type` as data fields' `valueType`.
     */
    public static function variables( array $graph, string $node_id ): array {
        $trigger_step = self::trigger_step( $graph['nodes'] );
        $items        = array_unique( array_merge( $trigger_step ? $trigger_step->provides() : [], DataRegistry::ALWAYS ) );

        $list = [];
        foreach ( $items as $id ) {
            $item = DataRegistry::items()[ $id ] ?? null;
            if ( $item ) {
                $list = array_merge( $list, self::entity_variables( $item, $id, [ $id => true ] ) );
            }
        }

        $by_id   = array_column( $graph['nodes'], null, 'id' );
        $parents = array_column( $graph['edges'], 'source', 'target' );
        // `ancestors()` walks up from the step, so reverse it to list from the trigger down.
        foreach ( array_reverse( array_keys( self::ancestors( $node_id, $parents ) ) ) as $id ) {
            $node = $by_id[ $id ] ?? null;
            $step = $node && $node['data']['block'] ? StepRegistry::get( $node['data']['block'] ) : null;
            foreach ( $step ? $step->outputs() : [] as $key => $output ) {
                /** @var Type $type */
                $type   = $output['type'];
                $list[] = [
                    'key'   => "steps.$id.$key",
                    'label' => $output['label'],
                    'type'  => $type->to_string(),
                ];
            }
        }
        return $list;
    }

    /**
     * Fields of an entity under a path prefix: its own fields first, then those of each relation to
     * one entity (the catalog sends them apart, as `fields` and `relations`). A relation back to a
     * type already on the path isn't followed.
     *
     * @param DataItem $entity Entity type.
     * @param string   $prefix Path so far, e.g. `order` or `order.customer`.
     * @param array    $seen   Entity type ids on the path => true.
     */
    private static function entity_variables( DataItem $entity, string $prefix, array $seen ): array {
        $list    = [];
        $related = [];
        foreach ( $entity->fields() as $key => $field ) {
            /** @var Type $type */
            $type = $field['value_type'];
            if ( ! $type->is( Type::ENTITY ) ) {
                $list[] = [
                    'key'   => "$prefix.$key",
                    'label' => $field['label'],
                    'type'  => $type->to_string(),
                ];
                continue;
            }
            $target = DataRegistry::entities()[ $type->entity_type() ] ?? null;
            if ( $target && ! isset( $seen[ $target->id() ] ) ) {
                $related[] = self::entity_variables( $target, "$prefix.$key", $seen + [ $target->id() => true ] );
            }
        }
        return array_merge( $list, ...$related );
    }

    /**
     * The chosen trigger step, or null.
     *
     * @param array[] $nodes Well-formed nodes.
     */
    private static function trigger_step( array $nodes ): ?Step {
        foreach ( $nodes as $node ) {
            if ( Step::KIND_TRIGGER === $node['kind'] ) {
                return $node['data']['block'] ? StepRegistry::get( $node['data']['block'] ) : null;
            }
        }
        return null;
    }

    /**
     * The one issue of a step, or null: its settings first (`validate()`), then its merge tags, then
     * data it needs. A trigger with no step chosen is no_trigger, reported above.
     *
     * @param array      $node      Well-formed node.
     * @param array|null $available Data items the trigger supplies plus the always ones; null without a trigger.
     * @param array[]    $by_id     Every node by id.
     * @param array      $above     Ids of the steps above this one => true.
     */
    private static function step_error( array $node, ?array $available, array $by_id, array $above ): ?array {
        $step = $node['data']['block'] ? StepRegistry::get( $node['data']['block'] ) : null;
        if ( ! $step ) {
            return Step::KIND_ACTION === $node['kind'] ? [
                'code'    => 'no_block',
                'message' => Messages::get( 'noBlock' ),
            ] : null;
        }

        $config = $node['data']['config'];
        $valid  = $step->validate( $config );
        if ( is_wp_error( $valid ) ) {
            return self::issue( 'invalid_config', $valid->get_error_message(), $valid->get_error_data()['field'] ?? null );
        }

        $tags = self::tag_error( $step, $config, $available, $by_id, $above );
        if ( $tags ) {
            return $tags;
        }

        $missing = null === $available ? [] : array_diff( $step->uses( $config ), $available );
        if ( $missing ) {
            $item = DataRegistry::items()[ reset( $missing ) ] ?? null;
            // Only a rules field reads data items besides the ones a step always needs.
            $rules = array_filter( $step->fields(), fn( Field $field ) => Field::RULES === $field->type() );
            return self::issue( 'missing_data', self::missing_message( $item ? $item->label() : reset( $missing ) ), $rules ? reset( $rules )->key() : null );
        }
        return null;
    }

    /**
     * First problem with the merge tags in a step's text fields, or null. Malformed tags, unknown
     * filters and unknown paths come first (invalid_config), then paths to data this step can't
     * reach (missing_data).
     *
     * @param Step       $step      Step.
     * @param array      $config    Sanitized config.
     * @param array|null $available Data items available; null without a trigger.
     * @param array[]    $by_id     Every node by id.
     * @param array      $above     Ids of the steps above => true.
     */
    private static function tag_error( Step $step, array $config, ?array $available, array $by_id, array $above ): ?array {
        static $template = null;
        $template        = $template ?? new Template();

        $missing = null;
        foreach ( $step->fields() as $field ) {
            $text = $config[ $field->key() ] ?? null;
            if ( ! is_string( $text ) || ! $field->accepts_variables() ) {
                continue;
            }
            $errors = $template->errors( $text );
            if ( $errors ) {
                return self::issue( 'invalid_config', $errors[0], $field->key() );
            }
            foreach ( $template->paths( $text ) as $path ) {
                $reach = self::reach( $path, $available, $by_id, $above );
                if ( null === $reach ) {
                    return self::issue( 'invalid_config', Messages::get( 'unknownVariable', '{{ ' . $path . ' }}' ), $field->key() );
                }
                if ( true !== $reach && ! $missing ) {
                    $missing = self::issue( 'missing_data', self::missing_message( $reach ), $field->key() );
                }
            }
        }
        return $missing;
    }

    /**
     * Whether a step can read a path: true, null when nothing defines it, or the label of the data
     * (an item, or the step whose output it is) that isn't available to it.
     *
     * @param string     $path      Tag path, e.g. `order.customer.first_name`, `steps.email.to`.
     * @param array|null $available Data items available; null without a trigger (not reported then).
     * @param array[]    $by_id     Every node by id.
     * @param array      $above     Ids of the steps above => true.
     * @return true|string|null
     */
    private static function reach( string $path, ?array $available, array $by_id, array $above ) {
        $segments = explode( '.', $path );
        if ( 'trigger' === $segments[0] ) {
            array_shift( $segments );
        }

        if ( 'steps' === $segments[0] ) {
            $node = $by_id[ $segments[1] ?? '' ] ?? null;
            $step = $node && $node['data']['block'] ? StepRegistry::get( $node['data']['block'] ) : null;
            if ( ! $step || 3 !== count( $segments ) || ! isset( $step->outputs()[ $segments[2] ] ) ) {
                return null;
            }
            return isset( $above[ $node['id'] ] ) ? true : $step->label();
        }

        $item = DataRegistry::items()[ $segments[0] ] ?? null;
        if ( ! $item || ! self::has_field( $item, array_slice( $segments, 1 ) ) ) {
            return null;
        }
        return null === $available || in_array( $item->id(), $available, true ) ? true : $item->label();
    }

    /**
     * Whether a field path exists on an entity, following relations: `customer.first_name` on order.
     *
     * @param DataItem $entity   Entity type.
     * @param string[] $segments Field keys.
     */
    private static function has_field( DataItem $entity, array $segments ): bool {
        $field = $segments ? ( $entity->fields()[ $segments[0] ] ?? null ) : null;
        if ( ! $field ) {
            return false;
        }
        if ( 1 === count( $segments ) ) {
            return true;
        }
        /** @var Type $type */
        $type   = $field['value_type'];
        $target = $type->is( Type::ENTITY ) ? ( DataRegistry::entities()[ $type->entity_type() ] ?? null ) : null;
        return $target ? self::has_field( $target, array_slice( $segments, 1 ) ) : false;
    }

    /**
     * Ids of the steps above a step => true: its parent, the parent's parent, up to the trigger.
     *
     * @param string $id      Step id.
     * @param array  $parents Target id => source id (one parent per step).
     */
    private static function ancestors( string $id, array $parents ): array {
        $above = [];
        while ( isset( $parents[ $id ] ) && ! isset( $above[ $parents[ $id ] ] ) ) {
            $id           = $parents[ $id ];
            $above[ $id ] = true;
        }
        return $above;
    }

    /**
     * @param string $label Data item or step label.
     */
    private static function missing_message( string $label ): string {
        return Messages::get( 'missingData', $label );
    }

    /**
     * @param string      $code    Issue code.
     * @param string      $message Message.
     * @param string|null $field   Field key it is about.
     */
    private static function issue( string $code, string $message, ?string $field ): array {
        $issue = [
            'code'    => $code,
            'message' => $message,
        ];
        if ( null !== $field && '' !== $field ) {
            $issue['field'] = $field;
        }
        return $issue;
    }

    /**
     * Node ids reachable from the trigger by following edges.
     *
     * @param array $graph Well-formed graph.
     */
    private static function reachable( array $graph ): array {
        $queue = [];
        foreach ( $graph['nodes'] as $node ) {
            if ( Step::KIND_TRIGGER === $node['kind'] ) {
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
     * @param string $message What's wrong with the request body.
     */
    private static function error( string $message ): \WP_Error {
        return new \WP_Error( 'yaymatik_invalid_workflow', $message, [ 'status' => 400 ] );
    }
}
