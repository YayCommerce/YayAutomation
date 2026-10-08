<?php
namespace YayAutomation\Workflow\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Comparison operators per field type. Ids are shared with the TS rule builder, which only adds labels.
 * Text comparisons ignore case.
 */
final class Operators {

    public const BY_TYPE = [
        DataItem::TYPE_NUMBER => [ 'equal', 'not_equal', 'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal' ],
        DataItem::TYPE_STRING => [ 'equal', 'not_equal', 'contains', 'not_contains', 'starts_with', 'ends_with', 'is_empty', 'is_not_empty' ],
        DataItem::TYPE_ENUM   => [ 'is', 'is_not' ],
        DataItem::TYPE_DATE   => [ 'before', 'after' ],
    ];

    /** Operators that compare against nothing. */
    private const NO_VALUE = [ 'is_empty', 'is_not_empty' ];

    public static function supports( string $type, string $operator ): bool {
        return in_array( $operator, self::BY_TYPE[ $type ] ?? [], true );
    }

    public static function needs_value( string $operator ): bool {
        return ! in_array( $operator, self::NO_VALUE, true );
    }

    /**
     * @param string $type     Field type.
     * @param string $operator Supported by the type.
     * @param mixed  $actual   Field value: float for numbers, `Y-m-d` for dates, string otherwise.
     * @param mixed  $expected Value from the rule as typed, or another field's value.
     */
    public static function compare( string $type, string $operator, $actual, $expected ): bool {
        if ( DataItem::TYPE_NUMBER === $type ) {
            return self::compare_numbers( $operator, (float) $actual, (float) $expected );
        }

        $actual   = (string) $actual;
        $lower    = strtolower( $actual );
        $expected = strtolower( (string) $expected );
        switch ( $operator ) {
            case 'equal':
                return $lower === $expected;
            case 'not_equal':
                return $lower !== $expected;
            // An empty needle always matches; checked first since PHP 7.4's strpos() rejects it.
            case 'contains':
                return '' === $expected || false !== strpos( $lower, $expected );
            case 'not_contains':
                return '' !== $expected && false === strpos( $lower, $expected );
            case 'starts_with':
                return '' === $expected || 0 === strpos( $lower, $expected );
            case 'ends_with':
                return '' === $expected || substr( $lower, -strlen( $expected ) ) === $expected;
            case 'is_empty':
                return '' === trim( $actual );
            case 'is_not_empty':
                return '' !== trim( $actual );
            // Enum values are slugs and dates are `Y-m-d`, so plain string comparison works for both.
            case 'is':
                return $lower === $expected;
            case 'is_not':
                return $lower !== $expected;
            case 'before':
                return '' !== $actual && $lower < $expected;
            case 'after':
                return '' !== $actual && $lower > $expected;
        }//end switch
        return false;
    }

    private static function compare_numbers( string $operator, float $actual, float $expected ): bool {
        switch ( $operator ) {
            case 'equal':
                return $actual === $expected;
            case 'not_equal':
                return $actual !== $expected;
            case 'greater_than':
                return $actual > $expected;
            case 'greater_or_equal':
                return $actual >= $expected;
            case 'less_than':
                return $actual < $expected;
            case 'less_or_equal':
                return $actual <= $expected;
        }
        return false;
    }
}
