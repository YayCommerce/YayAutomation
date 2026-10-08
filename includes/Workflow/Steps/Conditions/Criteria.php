<?php
namespace YayAutomation\Workflow\Steps\Conditions;

use YayAutomation\Workflow\Data\DataItem;
use YayAutomation\Workflow\Data\DataRegistry;
use YayAutomation\Workflow\Data\Operators;
use YayAutomation\Workflow\Steps\Core\Condition;

defined( 'ABSPATH' ) || exit;

/**
 * The one condition step: a list of criteria joined by AND (`all`) or OR (`any`).
 * Config: [ 'match' => 'all'|'any', 'rules' => [ [ 'field' => 'order.total', 'operator' => 'greater_or_equal', 'value' => '500', 'valueType' => 'value' ], … ] ].
 * `valueType` 'value': `value` is stored as typed and cast by the field type when compared. 'variable':
 * `value` is another field's key, compared with that field's value (same type; numbers, text, dates).
 */
class Criteria extends Condition {

    public const ID = 'condition';

    private const MATCHES = [ 'all', 'any' ];

    /** Types whose value may be another variable. Option lists keep their own options. */
    private const VARIABLE_VALUE_TYPES = [ DataItem::TYPE_NUMBER, DataItem::TYPE_STRING, DataItem::TYPE_DATE ];

    public function id(): string {
        return self::ID;
    }

    public function sanitize( $config ): array {
        $config = is_array( $config ) ? $config : [];
        $rules  = [];
        foreach ( is_array( $config['rules'] ?? null ) ? $config['rules'] : [] as $rule ) {
            $rule     = is_array( $rule ) ? $rule : [];
            $variable = 'variable' === ( $rule['valueType'] ?? '' );
            $value    = self::scalar( $rule['value'] ?? '' );
            $rules[]  = [
                'field'     => self::field_key( self::scalar( $rule['field'] ?? '' ) ),
                'operator'  => sanitize_key( self::scalar( $rule['operator'] ?? '' ) ),
                'value'     => $variable ? self::field_key( $value ) : sanitize_text_field( $value ),
                'valueType' => $variable ? 'variable' : 'value',
            ];
        }
        return [
            'match' => in_array( $config['match'] ?? '', self::MATCHES, true ) ? $config['match'] : 'all',
            'rules' => $rules,
        ];
    }

    public function validate( array $config ) {
        if ( ! array_filter( array_column( $config['rules'], 'field' ) ) ) {
            return $this->invalid( __( 'Criteria is required. Add a criteria for comparison, or remove this condition.', 'yay-automation' ) );
        }
        foreach ( $config['rules'] as $rule ) {
            $error = $this->rule_error( $rule );
            if ( $error ) {
                return $this->invalid( $error );
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

    public function evaluate( array $context, array $config ): bool {
        foreach ( $config['rules'] as $rule ) {
            $field    = DataRegistry::field( $rule['field'] );
            $expected = 'variable' === $rule['valueType'] && Operators::needs_value( $rule['operator'] )
                ? DataRegistry::value( $rule['value'], $context )
                : $rule['value'];
            // Comparing with a variable that has no value (e.g. no paid date yet) never matches.
            $result = null !== $expected
                && Operators::compare( $field['type'], $rule['operator'], DataRegistry::value( $rule['field'], $context ), $expected );
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
     * Why one criteria can't run, or null. Same messages and order as the TS step schema.
     *
     * @param array $rule Sanitized criteria.
     */
    private function rule_error( array $rule ): ?string {
        $field = DataRegistry::field( $rule['field'] );
        if ( ! $field || ! $field['conditional'] ) {
            return __( 'Choose a variable for each criteria.', 'yay-automation' );
        }
        if ( ! Operators::supports( $field['type'], $rule['operator'] ) ) {
            return __( 'Choose a comparison for each criteria.', 'yay-automation' );
        }
        if ( ! Operators::needs_value( $rule['operator'] ) ) {
            return null;
        }

        if ( 'variable' === $rule['valueType'] ) {
            $other = DataRegistry::field( $rule['value'] );
            $fits  = $other && $other['conditional'] && $other['type'] === $field['type']
                && in_array( $field['type'], self::VARIABLE_VALUE_TYPES, true );
            return $fits ? null : __( 'Choose a variable to compare with.', 'yay-automation' );
        }

        $value = $rule['value'];
        if ( '' === $value ) {
            /* translators: %s: variable, e.g. "Order total" */
            $required = sprintf( __( '%s is required.', 'yay-automation' ), $field['label'] );
            // Text can be checked for blank instead.
            return DataItem::TYPE_STRING === $field['type']
                /* translators: %s: comparison name, "Is empty" */
                ? $required . ' ' . sprintf( __( 'Select the "%s" operator to check for blank values.', 'yay-automation' ), __( 'Is empty', 'yay-automation' ) )
                : $required;
        }
        if ( DataItem::TYPE_NUMBER === $field['type'] && ! is_numeric( $value ) ) {
            return __( 'Enter a number.', 'yay-automation' );
        }
        if ( DataItem::TYPE_DATE === $field['type'] && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return __( 'Enter a date.', 'yay-automation' );
        }
        // Without the plugin that provides the options there's nothing to check against.
        $options = DataItem::TYPE_ENUM === $field['type'] ? ( $field['options'] )() : [];
        if ( $options && ! array_key_exists( $value, $options ) ) {
            return __( 'Choose one of the listed values.', 'yay-automation' );
        }
        return null;
    }

    /**
     * A field key as stored: `item.field`, lowercase letters and underscores.
     *
     * @param string $key Raw key.
     */
    private static function field_key( string $key ): string {
        return (string) preg_replace( '/[^a-z_.]/', '', $key );
    }

    /**
     * @param mixed $value Raw input.
     */
    private static function scalar( $value ): string {
        return is_scalar( $value ) ? trim( (string) $value ) : '';
    }
}
