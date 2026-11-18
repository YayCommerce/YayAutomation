<?php
namespace YayMatik\Workflow\Core\Definition;

use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Data\Template;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Steps\Action;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\Step;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Core\Steps\Trigger;
use YayMatik\Utils\Logger;
use YayMatik\Workflow\Extension\StepIndex;

defined( 'ABSPATH' ) || exit;

/**
 * Turns REST input into storable workflow parts, and finds the issues that block Activate and applying a draft.
 */
final class Validator {

    /**
     * New workflow from the POST body; `status` is ignored, it's created inactive.
     *
     * @param mixed $input { name?, graph? }.
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
     * Fields present in a PUT body; `status` is ignored (it changes only through activate / deactivate).
     *
     * @param mixed   $input  { name?, graph? }.
     * @param array[] $stored The workflow's saved nodes, live then draft (`GraphSchema::prepare()`).
     * @return array|\WP_Error [ 'name'? => string, 'graph'? => array ]
     */
    public static function prepare_changes( $input, array $stored = [] ) {
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
            $graph = GraphSchema::prepare( $input['graph'], $stored );
            if ( is_wp_error( $graph ) ) {
                return $graph;
            }
            $changes['graph'] = $graph;
        }
        return $changes;
    }

    /**
     * 400 listing everything `errors()` found.
     *
     * @param array[] $errors
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
     * @param mixed $name
     */
    private static function prepare_name( $name ): string {
        return sanitize_text_field( is_scalar( $name ) ? (string) $name : '' );
    }

    /**
     * Issues of a stored workflow's live graph and of its draft.
     *
     * @param array $workflow name, graph, draft.
     * @return array [ 'live' => issue[], 'draft' => issue[]|null ]
     */
    public static function issues( array $workflow ): array {
        $draft = $workflow['draft'] ?? null;
        return [
            'live'  => self::errors(
                [
                    'name'   => $workflow['name'],
                    'graph'  => $workflow['graph'],
                    'status' => $workflow['status'] ?? null,
                ]
            ),
            'draft' => null === $draft ? null : self::errors(
                [
                    'name'       => $workflow['name'],
                    'graph'      => $draft,
                    'status'     => $workflow['status'] ?? null,
                    'live_graph' => $workflow['graph'],
                ]
            ),
        ];
    }

    /**
     * `issues()` that never throws, so a workflow that can't be checked can still be listed, opened and fixed.
     *
     * @param array $workflow id, name, graph, draft.
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
     * Problems that block turning a workflow on or applying a draft.
     *
     * @param array $workflow Graph from `GraphSchema::prepare()`. `status`, `live_graph`: when `setup_checked()` applies.
     * @return array[] [ [ 'code' => string, 'message' => string, 'field'? => string, 'nodeId'? => string, 'nodeIds'? => string[] ], … ]
     */
    public static function errors( array $workflow ): array {
        // Mirrors `validateWorkflow()` in apps/admin-page/src/routes/workflows/model/validation.ts.
        $errors       = [];
        $nodes        = $workflow['graph']['nodes'];
        $trigger_step = self::trigger_step( $nodes );

        if ( '' === $workflow['name'] ) {
            $errors[] = [
                'code'    => 'name_required',
                'message' => Messages::get( 'nameRequired' ),
            ];
        }
        // A step of a plugin that's off, or one that needs YayMatik Pro: the workflow keeps it, but can't be turned on.
        $missing = [];
        $pro     = ProUsage::of( $workflow['graph'] );
        foreach ( $nodes as $node ) {
            $inactive = in_array( $node['id'], $pro, true ) ? null : self::inactive_step( $node );
            if ( in_array( $node['id'], $pro, true ) ) {
                $missing[ $node['id'] ] = true;
                $errors[]               = [
                    'code'    => 'needs_pro',
                    'message' => Messages::get( 'needsPro' ),
                    'nodeId'  => $node['id'],
                ];
            } elseif ( $inactive ) {
                $missing[ $node['id'] ] = true;
                $errors[]               = [
                    'code'    => 'missing_plugin',
                    'message' => Messages::get( 'missingPlugin', $inactive['plugin'] ),
                    'nodeId'  => $node['id'],
                ];
            }
        }
        // Workflow-level: there is no step to point at.
        if ( ! $trigger_step && ! isset( $missing[ GraphSchema::trigger( $nodes )['id'] ?? '' ] ) ) {
            $errors[] = [
                'code'    => 'no_trigger',
                'message' => Messages::get( 'noTrigger' ),
            ];
        }
        if ( $trigger_step && self::setup_checked( $trigger_step, $workflow ) ) {
            $setup = $trigger_step->setup_issue();
            if ( null !== $setup ) {
                $errors[] = [
                    'code'    => $trigger_step->setup_issue_code(),
                    'message' => $setup,
                    'nodeId'  => GraphSchema::trigger( $nodes )['id'],
                ];
            }
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
        $available = $trigger_step ? array_merge( $trigger_step->resolved_provides(), DataRegistry::ALWAYS ) : null;
        $by_id     = GraphSchema::by_id( $nodes );
        $parents   = GraphSchema::parents( $workflow['graph']['edges'] );
        foreach ( $nodes as $node ) {
            // Its settings are YayMatik Pro's to check.
            if ( in_array( $node['id'], $pro, true ) ) {
                continue;
            }
            $error = self::step_error( $node, $available, $trigger_step instanceof Trigger ? $trigger_step : null, $by_id, self::ancestors( $node['id'], $parents ) );
            if ( $error ) {
                $errors[] = $error + [ 'nodeId' => $node['id'] ];
            }
            $setup = Step::KIND_TRIGGER === $node['kind'] ? null : self::node_setup_issue( $node, $workflow );
            if ( null !== $setup ) {
                $errors[] = $setup + [ 'nodeId' => $node['id'] ];
            }
        }
        return $errors;
    }

    /**
     * Things to know that never block Activate: an action that can set off its own workflow's trigger.
     *
     * @param array $graph
     * @return array[] [ [ 'code' => string, 'nodeId' => string, 'message' => string ], … ]
     */
    public static function warnings( array $graph ): array {
        $node    = GraphSchema::trigger( $graph['nodes'] );
        $trigger = self::trigger_step( $graph['nodes'] );
        if ( ! $trigger instanceof Trigger || ! $trigger->fires_for_own_runs( $node['data']['config'] ) ) {
            return [];
        }
        $warnings = [];
        foreach ( $graph['nodes'] as $action ) {
            $step = $action['data']['block'] ? StepRegistry::get( $action['data']['block'] ) : null;
            if ( $step instanceof Action && in_array( $trigger->id(), $step->emits(), true ) ) {
                $warnings[] = [
                    'code'    => 'may_refire',
                    'nodeId'  => $action['id'],
                    'message' => Messages::get( 'mayRefire' ),
                ];
            }
        }
        return $warnings;
    }

    /**
     * `warnings()` of a stored workflow's live graph and draft; never throws, as they never block anything.
     *
     * @param array $workflow id, graph, draft.
     * @return array [ 'live' => warning[], 'draft' => warning[]|null ]
     */
    public static function list_warnings( array $workflow ): array {
        try {
            return [
                'live'  => self::warnings( $workflow['graph'] ),
                'draft' => isset( $workflow['draft'] ) ? self::warnings( $workflow['draft'] ) : null,
            ];
        } catch ( \Throwable $e ) {
            Logger::error( 'Could not check workflow ' . ( $workflow['id'] ?? '?' ) . ': ' . $e->getMessage(), [ 'workflow_id' => $workflow['id'] ?? null ] );
            return [
                'live'  => [],
                'draft' => null,
            ];
        }
    }

    /**
     * Variables one step can use, in the order "Insert variable" lists them; mirrors the TS `availableVariables()`.
     *
     * @param array  $graph From `GraphSchema::prepare()`.
     * @param string $node_id
     * @return array[] [ [ 'key' => string, 'label' => string, 'type' => string ], … ]
     */
    public static function variables( array $graph, string $node_id ): array {
        $trigger_step = self::trigger_step( $graph['nodes'] );
        $items        = array_unique( array_merge( $trigger_step ? $trigger_step->resolved_provides() : [], DataRegistry::ALWAYS ) );

        $list = [];
        foreach ( $trigger_step ? $trigger_step->facts() : [] as $key => $fact ) {
            $list[] = [
                'key'   => "trigger.$key",
                'label' => $fact['label'],
                'type'  => $fact['value_type']->to_string(),
            ];
        }
        foreach ( $items as $id ) {
            $item = DataRegistry::items()[ $id ] ?? null;
            if ( $item ) {
                $list = array_merge( $list, self::entity_variables( $item, $id, [ $id => true ] ) );
            }
        }

        $by_id   = GraphSchema::by_id( $graph['nodes'] );
        $parents = GraphSchema::parents( $graph['edges'] );
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
     * Fields of an entity under a path prefix: its own first, then each relation's. A relation back to a type
     * already on the path isn't followed.
     *
     * @param DataItem $entity
     * @param string   $prefix Path so far, e.g. `order.customer`.
     * @param array    $seen   Entity type ids on the path => true.
     */
    private static function entity_variables( DataItem $entity, string $prefix, array $seen ): array {
        $list    = [];
        $related = [];
        foreach ( $entity->fields() as $key => $field ) {
            /** @var Type $type */
            $type = $field['value_type'];
            if ( ! $field['merge_tag'] ) {
                continue;
            }
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
     * @param array[] $nodes
     */
    /**
     * Turning on, or a draft that changes the trigger; an active workflow keeps its trigger editable with tracking off.
     *
     * @param Step  $trigger
     * @param array $workflow
     */
    private static function setup_checked( Step $trigger, array $workflow ): bool {
        if ( isset( $workflow['status'] ) && 'active' !== $workflow['status'] ) {
            return true;
        }
        return isset( $workflow['live_graph'] ) && $trigger->id() !== ( GraphSchema::trigger( $workflow['live_graph']['nodes'] )['data']['block'] ?? null );
    }

    /**
     * The step index entry of a node whose step is another plugin's that isn't active now, or YayMatik Pro's; null otherwise.
     *
     * @param array $node
     */
    private static function inactive_step( array $node ): ?array {
        $block = (string) $node['data']['block'];
        if ( '' === $block ) {
            return null;
        }
        $inactive = StepIndex::inactive( $block );
        $pro      = $inactive || ! in_array( $block, ProCatalog::missing()['steps'], true ) ? null : ProCatalog::step( $block );
        // YayMatik Pro's step, kept while Pro isn't here: its outputs read like an inactive plugin's.
        return $pro ? [ 'outputs' => array_column( $pro['outputs'], 'label', 'key' ) ] + $pro : $inactive;
    }

    /**
     * A step's setup issue (`code`, `message`), checked like the trigger's: on turning on, or for a step the draft adds.
     *
     * @param array $node
     * @param array $workflow
     */
    private static function node_setup_issue( array $node, array $workflow ): ?array {
        $step = $node['data']['block'] ? StepRegistry::get( $node['data']['block'] ) : null;
        if ( ! $step ) {
            return null;
        }
        $checked = isset( $workflow['status'] ) && 'active' !== $workflow['status'];
        if ( ! $checked && isset( $workflow['live_graph'] ) ) {
            $live    = GraphSchema::by_id( $workflow['live_graph']['nodes'] )[ $node['id'] ] ?? null;
            $checked = ! $live || $live['data']['block'] !== $node['data']['block'];
        }
        $issue = $checked ? $step->setup_issue() : null;
        return null === $issue ? null : [
            'code'    => $step->setup_issue_code(),
            'message' => $issue,
        ];
    }

    /**
     * @param array[] $nodes
     */
    private static function trigger_step( array $nodes ): ?Step {
        $block = GraphSchema::trigger( $nodes )['data']['block'] ?? null;
        return $block ? StepRegistry::get( $block ) : null;
    }

    /**
     * The one issue of a step, or null: its settings first, then its merge tags, then data it needs.
     *
     * @param array        $node
     * @param array|null   $available Data item ids; null without a trigger.
     * @param Trigger|null $trigger
     * @param array[]      $by_id
     * @param array        $above     Step ids => true.
     */
    private static function step_error( array $node, ?array $available, ?Trigger $trigger, array $by_id, array $above ): ?array {
        $step = $node['data']['block'] ? StepRegistry::get( $node['data']['block'] ) : null;
        if ( ! $step && self::inactive_step( $node ) ) {
            return null;
        }
        if ( ! $step ) {
            return Step::KIND_ACTION === $node['kind'] ? [
                'code'    => 'no_block',
                'message' => Messages::get( 'noBlock' ),
            ] : null;
        }

        $config = $node['data']['config'];
        $valid  = $step->validate( $config );
        if ( is_wp_error( $valid ) ) {
            return self::config_issue( $valid );
        }
        $context = null === $available ? null : $step->context_error( $config, $available, $trigger );
        if ( $context ) {
            return self::config_issue( $context );
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
     * First problem with the merge tags in a step's text fields, or null; any invalid_config before missing_data.
     *
     * @param Step       $step
     * @param array      $config
     * @param array|null $available Data item ids; null without a trigger.
     * @param array[]    $by_id
     * @param array      $above     Step ids => true.
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
                $problem = self::reach( $path, $available, $by_id, $above );
                if ( null === $problem ) {
                    continue;
                }
                if ( 'invalid_config' === $problem[0] ) {
                    return self::issue( 'invalid_config', $problem[1], $field->key() );
                }
                $missing = $missing ?? self::issue( 'missing_data', $problem[1], $field->key() );
            }
        }
        return $missing;
    }

    /**
     * Why a step can't read a path, or null when it can: invalid_config when nothing defines it, missing_data
     * when the data or step isn't above it.
     *
     * @param string     $path
     * @param array|null $available Data item ids; null without a trigger (not reported then).
     * @param array[]    $by_id
     * @param array      $above     Step ids => true.
     * @return array{0: string, 1: string}|null
     */
    private static function reach( string $path, ?array $available, array $by_id, array $above ) {
        $segments = explode( '.', $path );
        if ( 'trigger' === $segments[0] ) {
            array_shift( $segments );
            $node     = GraphSchema::trigger( array_values( $by_id ) );
            $step     = self::trigger_step( array_values( $by_id ) );
            $inactive = $node ? self::inactive_step( $node ) : null;
            $facts    = $step instanceof Trigger ? $step->facts() : ( $inactive['facts'] ?? [] );
            if ( 1 === count( $segments ) && isset( $facts[ $segments[0] ] ) ) {
                return null;
            }
        }

        $tag     = '{{ ' . $path . ' }}';
        $unknown = [ 'invalid_config', Messages::get( 'unknownVariable', $tag ) ];

        if ( 'steps' === $segments[0] ) {
            if ( 3 !== count( $segments ) ) {
                return $unknown;
            }
            $node = $by_id[ $segments[1] ] ?? null;
            if ( ! $node ) {
                return [ 'invalid_config', Messages::get( 'stepGone', $tag ) ];
            }
            $step     = $node['data']['block'] ? StepRegistry::get( $node['data']['block'] ) : null;
            $inactive = $step ? null : self::inactive_step( $node );
            if ( $inactive && isset( $inactive['outputs'][ $segments[2] ] ) ) {
                return isset( $above[ $node['id'] ] ) ? null : [ 'missing_data', Messages::get( 'stepNotAbove', $tag, $inactive['label'] ) ];
            }
            if ( ! $step || ! isset( $step->outputs()[ $segments[2] ] ) ) {
                return $unknown;
            }
            return isset( $above[ $node['id'] ] ) ? null : [ 'missing_data', Messages::get( 'stepNotAbove', $tag, $step->label() ) ];
        }

        $item = DataRegistry::items()[ $segments[0] ] ?? null;
        if ( ! $item || ! self::has_field( $item, array_slice( $segments, 1 ) ) ) {
            return $unknown;
        }
        return null === $available || in_array( $item->id(), $available, true ) ? null : [ 'missing_data', self::missing_message( $item->label() ) ];
    }

    /**
     * Whether a field path exists on an entity, following relations.
     *
     * @param DataItem $entity
     * @param string[] $segments
     */
    private static function has_field( DataItem $entity, array $segments ): bool {
        $field = $segments ? ( $entity->fields()[ $segments[0] ] ?? null ) : null;
        if ( ! $field ) {
            return false;
        }
        if ( 1 === count( $segments ) ) {
            return $field['merge_tag'];
        }
        /** @var Type $type */
        $type   = $field['value_type'];
        $target = $type->is( Type::ENTITY ) ? ( DataRegistry::entities()[ $type->entity_type() ] ?? null ) : null;
        return $target ? self::has_field( $target, array_slice( $segments, 1 ) ) : false;
    }

    /**
     * Ids of the steps above a step, from the trigger down.
     *
     * @param array  $graph
     * @param string $node_id
     * @return string[]
     */
    public static function steps_above( array $graph, string $node_id ): array {
        $parents = GraphSchema::parents( $graph['edges'] );
        return array_map( 'strval', array_reverse( array_keys( self::ancestors( $node_id, $parents ) ) ) );
    }

    /**
     * Ids of the steps above a step => true, walking up to the trigger.
     *
     * @param string $id
     * @param array  $parents Target id => source id.
     */
    private static function ancestors( string $id, array $parents ): array {
        $above = [];
        while ( isset( $parents[ $id ] ) && ! isset( $above[ $parents[ $id ] ] ) ) {
            $id           = $parents[ $id ];
            $above[ $id ] = true;
        }
        return $above;
    }

    private static function missing_message( string $label ): string {
        return Messages::get( 'missingData', $label );
    }

    /**
     * A settings error as an invalid_config issue, with the field and condition rule it is about.
     *
     * @param \WP_Error $error
     */
    private static function config_issue( \WP_Error $error ): array {
        $issue = self::issue( 'invalid_config', $error->get_error_message(), $error->get_error_data()['field'] ?? null );
        $rule  = $error->get_error_data()['rule'] ?? null;
        if ( is_array( $rule ) ) {
            $issue['rule'] = array_map( 'intval', $rule );
        }
        return $issue;
    }

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
     * Node ids reachable from the trigger.
     *
     * @param array $graph
     */
    private static function reachable( array $graph ): array {
        $trigger = GraphSchema::trigger( $graph['nodes'] );
        $queue   = $trigger ? [ $trigger['id'] ] : [];
        $seen    = [];
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

    private static function error( string $message ): \WP_Error {
        return new \WP_Error( 'yaymatik_invalid_workflow', $message, [ 'status' => 400 ] );
    }
}
