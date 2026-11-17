<?php
namespace YayMatik\Pro\Workflow\Conditions;

use YayMatik\Workflow\Conditions\Criteria;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Data\Operators;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Definition\Messages;
use YayMatik\Workflow\Core\Steps\Trigger;

defined( 'ABSPATH' ) || exit;

// A rule may compare with another field (`valueType` `variable`, `value` is its key) or read a custom field (`key`, on a
// meta field); an item of `rules` may be a group, [ 'match', 'rules' ], one level deep.

/** The condition step in YayMatik Pro: groups, comparing with another field, custom field rules. */
class CriteriaPro extends Criteria {

    /** Types whose value may be another variable. Option lists keep their own options. */
    private const VARIABLE_VALUE_TYPES = [ DataItem::TYPE_NUMBER, DataItem::TYPE_STRING, DataItem::TYPE_DATE ];

    /**
     * Custom field names the config's rules on a meta field, e.g. `customer.meta`, read.
     *
     * @param array  $config
     * @param string $field
     * @return string[]
     */
    public static function meta_keys( array $config, string $field ): array {
        $keys = [];
        foreach ( ( new self() )->flat_rules( $config['rules'] ?? [] ) as $rule ) {
            if ( ( $rule['field'] ?? '' ) === $field && '' !== ( $rule['key'] ?? '' ) ) {
                $keys[] = $rule['key'];
            }
        }
        return $keys;
    }

    /**
     * An empty group counts with the rules not on facts.
     *
     * @param int          $index
     * @param array        $item
     * @param bool         $facts
     * @param Trigger|null $trigger
     */
    protected function item_error( int $index, array $item, bool $facts, ?Trigger $trigger ): ?\WP_Error {
        if ( ! self::is_group( $item ) ) {
            return parent::item_error( $index, $item, $facts, $trigger );
        }
        if ( ! $facts && ! array_filter( array_column( $item['rules'], 'field' ) ) ) {
            return $this->invalid( Messages::get( 'groupRulesRequired' ), 'rules', [ 'rule' => [ $index ] ] );
        }
        foreach ( $item['rules'] as $inner => $rule ) {
            $error = $this->reads_fact( $rule ) === $facts ? $this->rule_error( $rule, $trigger ) : null;
            if ( $error ) {
                // Where the form draws it: [ group, rule ].
                return $this->invalid( $error, 'rules', [ 'rule' => [ $index, $inner ] ] );
            }
        }
        return null;
    }

    /**
     * As its variable or as the one it compares with.
     *
     * @param array $rule
     */
    protected function reads_fact( array $rule ): bool {
        return parent::reads_fact( $rule ) || ( 'variable' === $rule['valueType'] && 0 === strpos( (string) $rule['value'], 'trigger.' ) );
    }

    /**
     * @param array $rule
     * @return string[]
     */
    protected function reads( array $rule ): array {
        $keys = parent::reads( $rule );
        if ( 'variable' === $rule['valueType'] && Operators::needs_value( $rule['operator'] ) ) {
            $keys[] = $rule['value'];
        }
        return $keys;
    }

    /**
     * @param Scope $scope
     * @param array $item
     */
    protected function item_matches( Scope $scope, array $item ): bool {
        return self::is_group( $item ) ? $this->evaluate( $scope, $item ) : parent::item_matches( $scope, $item );
    }

    /**
     * @param array $item
     * @return array[]
     */
    protected function rules_in( array $item ): array {
        return self::is_group( $item ) ? $item['rules'] : [ $item ];
    }

    /**
     * One level deep: a group inside a group is dropped.
     *
     * @param array $item
     */
    protected function sanitize_item( array $item ): array {
        if ( ! self::is_group( $item ) ) {
            return parent::sanitize_item( $item );
        }
        $rules = array_filter( $item['rules'], fn( $rule ) => is_array( $rule ) && ! self::is_group( $rule ) );
        return [
            'match' => in_array( $item['match'] ?? '', self::MATCHES, true ) ? $item['match'] : 'all',
            'rules' => array_values( array_map( [ $this, 'sanitize_rule' ], $rules ) ),
        ];
    }

    /**
     * @param Scope $scope
     * @param array $rule
     */
    protected function rule_matches( Scope $scope, array $rule ): bool {
        $operator = $rule['operator'];
        $field    = DataRegistry::field( $rule['field'], $scope->trigger() );
        if ( $field && DataItem::TYPE_META === $field['type'] ) {
            return Operators::compare( DataItem::TYPE_META, $operator, $scope->meta( $rule['field'], $rule['key'] ), $rule['value'] );
        }
        if ( ! $field || 'variable' !== $rule['valueType'] || ! Operators::needs_value( $operator ) ) {
            return parent::rule_matches( $scope, $rule );
        }
        $expected = $scope->get( $rule['value'] );
        // Comparing with a variable that has no value (e.g. no paid date yet) never matches.
        return null !== $expected && Operators::compare( $field['type'], $operator, $scope->get( $rule['field'] ), $expected );
    }

    /**
     * @param array $rule
     */
    protected function sanitize_rule( array $rule ): array {
        $clean = parent::sanitize_rule( $rule );
        $meta  = DataItem::TYPE_META === ( DataRegistry::field( $clean['field'] )['type'] ?? '' );
        $fixed = $meta || Operators::takes_list( $clean['operator'] ) || Operators::is_relative( $clean['operator'] );
        if ( ! $fixed && 'variable' === ( $rule['valueType'] ?? '' ) ) {
            $clean['value']     = self::field_key( self::scalar( $rule['value'] ?? '' ) );
            $clean['valueType'] = 'variable';
        }
        if ( $meta ) {
            $clean['key'] = sanitize_text_field( self::scalar( $rule['key'] ?? '' ) );
        }
        return $clean;
    }

    /**
     * Custom field and compare-with checks, in the TS step schema's order.
     *
     * @param array        $rule
     * @param Trigger|null $trigger
     */
    protected function rule_error( array $rule, ?Trigger $trigger ): ?string {
        $field = DataRegistry::field( $rule['field'], $trigger );
        if ( ! $field || ! $field['conditional'] || ! Operators::supports( $field['type'], $rule['operator'] ) ) {
            return parent::rule_error( $rule, $trigger );
        }
        if ( DataItem::TYPE_META === $field['type'] ) {
            if ( '' === $rule['key'] ) {
                return Messages::get( 'ruleKeyRequired' );
            }
            if ( $field['meta_blocked'] && ( $field['meta_blocked'] )( $rule['key'] ) ) {
                return Messages::get( 'ruleMetaKey' );
            }
        }
        $compares = 'variable' === $rule['valueType'] && Operators::needs_value( $rule['operator'] )
            && ! Operators::takes_list( $rule['operator'] ) && ! Operators::is_relative( $rule['operator'] );
        if ( ! $compares ) {
            return parent::rule_error( $rule, $trigger );
        }
        $other = DataRegistry::field( $rule['value'], $trigger );
        $fits  = $other && $other['conditional'] && $other['type'] === $field['type']
            && in_array( $field['type'], self::VARIABLE_VALUE_TYPES, true );
        return $fits ? null : Messages::get( 'ruleCompareWith' );
    }

    /**
     * @param array $item
     */
    private static function is_group( array $item ): bool {
        return is_array( $item['rules'] ?? null );
    }
}
