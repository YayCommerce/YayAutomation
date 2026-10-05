<?php
namespace YayAutomation\Engine\Blocks\Conditions;

use YayAutomation\Engine\Blocks\Core\Condition;
use YayAutomation\Engine\Data\DataItem;
use YayAutomation\Engine\Data\DataRegistry;
use YayAutomation\Engine\Data\Operators;

defined( 'ABSPATH' ) || exit;

/**
 * The one condition block: a list of criteria joined by AND (`all`) or OR (`any`).
 * Config: [ 'match' => 'all'|'any', 'rules' => [ [ 'field' => 'order.total', 'operator' => 'greater_or_equal', 'value' => '500' ], … ] ].
 * Values are stored as typed in the form and cast by the field type when compared.
 */
class Criteria extends Condition {

    public const ID = 'condition';

    private const MATCHES = [ 'all', 'any' ];

    public function id(): string {
        return self::ID;
    }

    public function sanitize( $config ): array {
        $config = is_array( $config ) ? $config : [];
        $rules  = [];
        foreach ( is_array( $config['rules'] ?? null ) ? $config['rules'] : [] as $rule ) {
            $rule    = is_array( $rule ) ? $rule : [];
            $rules[] = [
                'field'    => (string) preg_replace( '/[^a-z_.]/', '', self::scalar( $rule['field'] ?? '' ) ),
                'operator' => sanitize_key( self::scalar( $rule['operator'] ?? '' ) ),
                'value'    => sanitize_text_field( self::scalar( $rule['value'] ?? '' ) ),
            ];
        }
        return [
            'match' => in_array( $config['match'] ?? '', self::MATCHES, true ) ? $config['match'] : 'all',
            'rules' => $rules,
        ];
    }

    public function validate( array $config ) {
        if ( ! $config['rules'] ) {
            return $this->invalid( __( 'Add at least one criteria.', 'yay-automation' ) );
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
        $items = array_map( fn( array $rule ) => DataRegistry::item_of( $rule['field'] ), $config['rules'] );
        return array_values( array_diff( array_unique( $items ), DataRegistry::ALWAYS ) );
    }

    public function evaluate( array $context, array $config ): bool {
        foreach ( $config['rules'] as $rule ) {
            $field  = DataRegistry::field( $rule['field'] );
            $result = Operators::compare( $field['type'], $rule['operator'], DataRegistry::value( $rule['field'], $context ), $rule['value'] );
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
     * Why one criteria can't run, or null. Same messages and order as the TS block schema.
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

        $value = $rule['value'];
        if ( '' === $value ) {
            return __( 'Enter a value for each criteria.', 'yay-automation' );
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
     * @param mixed $value Raw input.
     */
    private static function scalar( $value ): string {
        return is_scalar( $value ) ? trim( (string) $value ) : '';
    }
}
