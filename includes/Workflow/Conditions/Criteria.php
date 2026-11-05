<?php
namespace YayMatik\Workflow\Conditions;

use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Data\Operators;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Definition\Messages;
use YayMatik\Workflow\Core\Steps\Condition;
use YayMatik\Workflow\Core\Steps\Field;

defined( 'ABSPATH' ) || exit;

/**
 * The one condition step. Rule: [ 'field', 'operator', 'value' (a list for list comparisons, a whole number with `unit` for relative dates), 'valueType' => 'value'|'variable' (`value` is another field's key), 'unit'?, 'key'? (a meta field's custom field) ].
 */
class Criteria extends Condition {

    public const ID = 'condition';

    private const MATCHES = [ 'all', 'any' ];

    /** Types whose value may be another variable. Option lists keep their own options. */
    private const VARIABLE_VALUE_TYPES = [ DataItem::TYPE_NUMBER, DataItem::TYPE_STRING, DataItem::TYPE_DATE ];

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Condition', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Take the Yes path when the rules match, otherwise the No path.', 'yaymatik' );
    }

    public function icon(): string {
        return 'git-branch';
    }

    public function fields(): array {
        return [
            Field::select(
                'match',
                __( 'Match', 'yaymatik' ),
                [
                    'all' => __( 'All', 'yaymatik' ),
                    'any' => __( 'Any', 'yaymatik' ),
                ]
            )->default( 'all' ),
            Field::rules( 'rules', __( 'Rules', 'yaymatik' ) )->required(),
        ];
    }

    public function summary(): string {
        return '%rules%';
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
            $rules[] = self::sanitize_rule( is_array( $rule ) ? $rule : [] );
        }
        return [
            'match' => in_array( $config['match'] ?? '', self::MATCHES, true ) ? $config['match'] : 'all',
            'rules' => $rules,
        ];
    }

    public function validate( array $config ) {
        if ( ! array_filter( array_column( $config['rules'], 'field' ) ) ) {
            return $this->invalid( Messages::get( 'rulesRequired' ), 'rules' );
        }
        foreach ( $config['rules'] as $rule ) {
            $error = $this->rule_error( $rule );
            if ( $error ) {
                return $this->invalid( $error, 'rules' );
            }
        }
        return true;
    }

    public function uses( array $config ): array {
        $keys = [];
        foreach ( $config['rules'] as $rule ) {
            $keys[] = $rule['field'];
            if ( 'variable' === $rule['valueType'] && Operators::needs_value( $rule['operator'] ) ) {
                $keys[] = $rule['value'];
            }
        }
        $items = array_map( [ DataRegistry::class, 'item_of' ], array_filter( $keys ) );
        return array_values( array_diff( array_unique( $items ), DataRegistry::ALWAYS ) );
    }

    /**
     * Custom field names the config's rules on a meta field, e.g. `customer.meta`, read.
     *
     * @param array  $config
     * @param string $field
     * @return string[]
     */
    public static function meta_keys( array $config, string $field ): array {
        $keys = [];
        foreach ( $config['rules'] ?? [] as $rule ) {
            if ( ( $rule['field'] ?? '' ) === $field && '' !== ( $rule['key'] ?? '' ) ) {
                $keys[] = $rule['key'];
            }
        }
        return $keys;
    }

    public function evaluate( Scope $scope, array $config ): bool {
        foreach ( $config['rules'] as $rule ) {
            $result = self::rule_matches( $scope, $rule );
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
     * @param array $rule
     */
    private static function rule_matches( Scope $scope, array $rule ): bool {
        $operator = $rule['operator'];
        $field    = DataRegistry::field( $rule['field'] );
        if ( DataItem::TYPE_META === $field['type'] ) {
            return Operators::compare( DataItem::TYPE_META, $operator, $scope->meta( $rule['field'], $rule['key'] ), $rule['value'] );
        }
        if ( Operators::is_relative( $operator ) ) {
            $at = $scope->timestamp( $rule['field'] );
            return 'in_last' === $operator
                ? Operators::in_last( $at, (int) $rule['value'], $rule['unit'], time() )
                : Operators::not_in_last( $at, (int) $rule['value'], $rule['unit'], time() );
        }
        $expected = 'variable' === $rule['valueType'] && Operators::needs_value( $operator )
            ? $scope->get( $rule['value'] )
            : $rule['value'];
        // Comparing with a variable that has no value (e.g. no paid date yet) never matches.
        return null !== $expected
            && Operators::compare( $field['type'], $operator, $scope->get( $rule['field'] ), $expected );
    }

    /**
     * One rule in its stored shape; a plain rule keeps exactly its four keys, so old drafts don't read as edited.
     *
     * @param array $rule
     */
    private static function sanitize_rule( array $rule ): array {
        $key      = self::field_key( self::scalar( $rule['field'] ?? '' ) );
        $meta     = DataItem::TYPE_META === ( DataRegistry::field( $key )['type'] ?? '' );
        $operator = sanitize_key( self::scalar( $rule['operator'] ?? '' ) );
        $fixed    = $meta || Operators::takes_list( $operator ) || Operators::is_relative( $operator );
        $variable = ! $fixed && 'variable' === ( $rule['valueType'] ?? '' );
        $value    = self::scalar( $rule['value'] ?? '' );
        $clean    = [
            'field'     => $key,
            'operator'  => $operator,
            'value'     => $variable ? self::field_key( $value ) : sanitize_text_field( $value ),
            'valueType' => $variable ? 'variable' : 'value',
        ];
        if ( Operators::takes_list( $operator ) ) {
            $items          = array_map( [ self::class, 'scalar' ], (array) ( $rule['value'] ?? [] ) );
            $clean['value'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $items ), 'strlen' ) ) );
        }
        if ( Operators::is_relative( $operator ) ) {
            $clean['unit'] = in_array( $rule['unit'] ?? '', Operators::units(), true ) ? $rule['unit'] : 'days';
        }
        if ( $meta ) {
            $clean['key'] = sanitize_text_field( self::scalar( $rule['key'] ?? '' ) );
        }
        return $clean;
    }

    /**
     * Why one criteria can't run, or null. Same messages and order as the TS step schema.
     *
     * @param array $rule
     */
    private function rule_error( array $rule ): ?string {
        $field = DataRegistry::field( $rule['field'] );
        if ( ! $field || ! $field['conditional'] ) {
            return Messages::get( 'ruleVariable' );
        }
        if ( ! Operators::supports( $field['type'], $rule['operator'] ) ) {
            return Messages::get( 'ruleComparison' );
        }
        if ( DataItem::TYPE_META === $field['type'] ) {
            if ( '' === $rule['key'] ) {
                return Messages::get( 'ruleKeyRequired' );
            }
            if ( $field['meta_blocked'] && ( $field['meta_blocked'] )( $rule['key'] ) ) {
                return Messages::get( 'ruleMetaKey' );
            }
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

        if ( 'variable' === $rule['valueType'] ) {
            $other = DataRegistry::field( $rule['value'] );
            $fits  = $other && $other['conditional'] && $other['type'] === $field['type']
                && in_array( $field['type'], self::VARIABLE_VALUE_TYPES, true );
            return $fits ? null : Messages::get( 'ruleCompareWith' );
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
    private static function field_key( string $key ): string {
        return (string) preg_replace( '/[^a-z_.]/', '', $key );
    }
}
