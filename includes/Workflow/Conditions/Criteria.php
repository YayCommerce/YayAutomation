<?php
namespace YayMatik\Workflow\Conditions;

use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Data\Operators;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Definition\Messages;
use YayMatik\Workflow\Core\Steps\Condition;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\StepResult;
use YayMatik\Workflow\Core\Steps\Trigger;
use YayMatik\Workflow\Extension\StepIndex;

defined( 'ABSPATH' ) || exit;

/**
 * The one condition step: a flat list of rules with fixed values. Rule: [ 'field', 'operator', 'value' (a list for list comparisons, a whole number with `unit` for relative dates), 'valueType' => 'value', 'unit'? ].
 */
class Criteria extends Condition {

    public const ID = 'condition';

    protected const MATCHES = [ 'all', 'any' ];

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Condition', 'yaymatik-automation-for-woocommerce' );
    }

    public function description(): string {
        return __( 'Take the Yes path when the rules match, otherwise the No path.', 'yaymatik-automation-for-woocommerce' );
    }

    public function icon(): string {
        return 'git-branch';
    }

    public function fields(): array {
        return [
            Field::select(
                'match',
                __( 'Match', 'yaymatik-automation-for-woocommerce' ),
                [
                    'all' => __( 'All', 'yaymatik-automation-for-woocommerce' ),
                    'any' => __( 'Any', 'yaymatik-automation-for-woocommerce' ),
                ]
            )->default( 'all' ),
            Field::rules( 'rules', __( 'Rules', 'yaymatik-automation-for-woocommerce' ) )->required(),
        ];
    }

    /** @return string[] What its rules may do besides a flat list of fixed values, as `ProCatalog::FEATURES` names them. */
    public function features(): array {
        return [];
    }

    public function summary(): string {
        return '{{ config.rules }}';
    }

    /**
     * Each rule is cleaned by the type of the variable it compares, so it keeps its own sanitize.
     *
     * @param mixed $config
     */
    public function sanitize( $config ): array {
        $config = is_array( $config ) ? $config : [];
        $rules  = [];
        foreach ( is_array( $config['rules'] ?? null ) ? $config['rules'] : [] as $rule ) {
            $rules[] = $this->sanitize_item( is_array( $rule ) ? $rule : [] );
        }
        return [
            'match' => in_array( $config['match'] ?? '', self::MATCHES, true ) ? $config['match'] : 'all',
            'rules' => $rules,
        ];
    }

    public function validate( array $config ) {
        if ( ! array_filter( array_column( $this->flat_rules( $config['rules'] ), 'field' ) ) ) {
            return $this->invalid( Messages::get( 'rulesRequired' ), 'rules' );
        }
        return $this->rules_error( $config, false, null ) ?? true;
    }

    /**
     * Rules on a trigger fact: only the workflow's trigger knows its type.
     *
     * @param array        $config
     * @param array        $available
     * @param Trigger|null $trigger
     */
    public function context_error( array $config, array $available, ?Trigger $trigger ): ?\WP_Error {
        return $this->rules_error( $config, true, $trigger );
    }

    /**
     * The first broken rule, either those on trigger facts or the others.
     *
     * @param array        $config
     * @param bool         $facts
     * @param Trigger|null $trigger
     */
    private function rules_error( array $config, bool $facts, ?Trigger $trigger ): ?\WP_Error {
        // A run whose trigger's plugin is off has no trigger: `run()` says so instead of a settings error.
        if ( $facts && ! $trigger ) {
            return null;
        }
        foreach ( $config['rules'] as $index => $item ) {
            $error = $this->item_error( $index, $item, $facts, $trigger );
            if ( $error ) {
                return $error;
            }
        }
        return null;
    }

    /**
     * The first broken rule of one item of `rules`.
     *
     * @param int          $index
     * @param array        $item
     * @param bool         $facts
     * @param Trigger|null $trigger
     */
    protected function item_error( int $index, array $item, bool $facts, ?Trigger $trigger ): ?\WP_Error {
        $error = $this->reads_fact( $item ) === $facts ? $this->rule_error( $item, $trigger ) : null;
        // Where the form draws it.
        return $error ? $this->invalid( $error, 'rules', [ 'rule' => [ $index ] ] ) : null;
    }

    /**
     * Whether a rule reads a trigger fact.
     *
     * @param array $rule
     */
    protected function reads_fact( array $rule ): bool {
        return 0 === strpos( $rule['field'], 'trigger.' );
    }

    /**
     * Data keys a rule reads.
     *
     * @param array $rule
     * @return string[]
     */
    protected function reads( array $rule ): array {
        return [ $rule['field'] ];
    }

    public function uses( array $config ): array {
        $keys = [];
        foreach ( $this->flat_rules( $config['rules'] ) as $rule ) {
            $keys = array_merge( $keys, $this->reads( $rule ) );
        }
        // A fact isn't a data item: `context_error()` checks it against the workflow's trigger.
        $keys  = array_filter( $keys, fn( string $key ) => '' !== $key && 0 !== strpos( $key, 'trigger.' ) );
        $items = array_map( [ DataRegistry::class, 'item_of' ], $keys );
        return array_values( array_diff( array_unique( $items ), DataRegistry::ALWAYS ) );
    }

    /**
     * The plugin, off now, whose data a rule reads; null when none.
     *
     * @param array $config
     */
    public function inactive_plugin( array $config ): ?string {
        foreach ( $this->flat_rules( $config['rules'] ) as $rule ) {
            foreach ( $this->reads( $rule ) as $key ) {
                // A trigger's facts: `run()` fails the step when the trigger's plugin is off.
                $plugin = '' === $key || 0 === strpos( $key, 'trigger.' ) ? null : StepIndex::inactive_plugin( $key );
                if ( $plugin ) {
                    return $plugin;
                }
            }
        }
        return null;
    }

    public function run( Scope $scope, array $config ): StepResult {
        if ( ! $scope->trigger() && array_filter( $this->flat_rules( $config['rules'] ), [ $this, 'reads_fact' ] ) ) {
            return StepResult::failed( __( 'This condition reads the trigger’s values, but the trigger’s plugin isn’t active.', 'yaymatik-automation-for-woocommerce' ) );
        }
        return parent::run( $scope, $config );
    }

    public function evaluate( Scope $scope, array $config ): bool {
        foreach ( $config['rules'] as $item ) {
            $result = $this->item_matches( $scope, $item );
            if ( 'any' === $config['match'] && $result ) {
                return true;
            }
            if ( 'all' === $config['match'] && ! $result ) {
                return false;
            }
        }
        return 'all' === $config['match'];
    }

    /**
     * @param Scope $scope
     * @param array $item One of `rules`.
     */
    protected function item_matches( Scope $scope, array $item ): bool {
        return $this->rule_matches( $scope, $item );
    }

    /**
     * The rules of one item of `rules`.
     *
     * @param array $item
     * @return array[]
     */
    protected function rules_in( array $item ): array {
        return [ $item ];
    }

    /**
     * Every rule, in order.
     *
     * @param array[] $items
     */
    protected function flat_rules( array $items ): array {
        $rules = [];
        foreach ( $items as $item ) {
            $rules = array_merge( $rules, $this->rules_in( $item ) );
        }
        return $rules;
    }

    /**
     * @param array $item One of `rules`.
     */
    protected function sanitize_item( array $item ): array {
        return $this->sanitize_rule( $item );
    }

    /**
     * @param Scope $scope
     * @param array $rule
     */
    protected function rule_matches( Scope $scope, array $rule ): bool {
        $operator = $rule['operator'];
        $field    = DataRegistry::field( $rule['field'], $scope->trigger() );
        if ( ! $field ) {
            return false;
        }
        if ( Operators::is_relative( $operator ) ) {
            $at = $scope->timestamp( $rule['field'] );
            return 'in_last' === $operator
                ? Operators::in_last( $at, (int) $rule['value'], $rule['unit'], time() )
                : Operators::not_in_last( $at, (int) $rule['value'], $rule['unit'], time() );
        }
        return Operators::compare( $field['type'], $operator, $scope->get( $rule['field'] ), $rule['value'] );
    }

    /**
     * One rule in its stored shape; a plain rule keeps exactly its four keys, so old drafts don't read as edited.
     *
     * @param array $rule
     */
    protected function sanitize_rule( array $rule ): array {
        $operator = sanitize_key( self::scalar( $rule['operator'] ?? '' ) );
        $clean    = [
            'field'     => self::field_key( self::scalar( $rule['field'] ?? '' ) ),
            'operator'  => $operator,
            'value'     => sanitize_text_field( self::scalar( $rule['value'] ?? '' ) ),
            'valueType' => 'value',
        ];
        if ( Operators::takes_list( $operator ) ) {
            $items          = array_map( [ self::class, 'scalar' ], (array) ( $rule['value'] ?? [] ) );
            $clean['value'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $items ), 'strlen' ) ) );
        }
        if ( Operators::is_relative( $operator ) ) {
            $clean['unit'] = in_array( $rule['unit'] ?? '', Operators::units(), true ) ? $rule['unit'] : 'days';
        }
        return $clean;
    }

    /**
     * Why one criteria can't run, or null. Same messages and order as the TS step schema.
     *
     * @param array        $rule
     * @param Trigger|null $trigger
     */
    protected function rule_error( array $rule, ?Trigger $trigger ): ?string {
        $field = DataRegistry::field( $rule['field'], $trigger );
        if ( ! $field || ! $field['conditional'] ) {
            return Messages::get( 'ruleVariable' );
        }
        if ( ! Operators::supports( $field['type'], $rule['operator'] ) ) {
            return Messages::get( 'ruleComparison' );
        }
        if ( ! Operators::needs_value( $rule['operator'] ) ) {
            return null;
        }

        if ( Operators::takes_list( $rule['operator'] ) ) {
            if ( ! $rule['value'] ) {
                return Messages::get( 'ruleListRequired' );
            }
            $options = $field['options'] ? ( $field['options'] )() : [];
            return $options && array_diff( $rule['value'], array_map( 'strval', array_keys( $options ) ) ) ? Messages::get( 'ruleOption' ) : null;
        }
        if ( Operators::is_relative( $rule['operator'] ) ) {
            return preg_match( '/^[1-9]\d*$/', $rule['value'] ) ? null : Messages::get( 'ruleAmount' );
        }

        $value = $rule['value'];
        if ( '' === $value ) {
            $required = Messages::get( 'ruleValueRequired', $field['label'] );
            // Text can be checked for blank instead.
            return DataItem::TYPE_STRING === $field['type']
                ? $required . ' ' . Messages::get( 'ruleBlankHint', Operators::labels()['is_empty'] )
                : $required;
        }
        if ( DataItem::TYPE_NUMBER === $field['type'] && ! is_numeric( $value ) ) {
            return Messages::get( 'ruleNumber' );
        }
        if ( DataItem::TYPE_DATE === $field['type'] && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return Messages::get( 'ruleDate' );
        }
        // Without the plugin that provides the options there's nothing to check against.
        $options = DataItem::TYPE_ENUM === $field['type'] ? ( $field['options'] )() : [];
        if ( $options && ! array_key_exists( $value, $options ) ) {
            return Messages::get( 'ruleOption' );
        }
        return null;
    }

    /**
     * A field key as stored: `item.field`, lowercase letters and underscores.
     *
     * @param string $key
     */
    protected static function field_key( string $key ): string {
        return (string) preg_replace( '/[^a-z0-9_.]/', '', $key );
    }
}
