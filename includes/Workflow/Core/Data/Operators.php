<?php
namespace YayMatik\Workflow\Core\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Comparison operators per field type, with their labels; text comparisons ignore case.
 */
final class Operators {

    public const BY_TYPE = [
        DataItem::TYPE_NUMBER  => [ 'equal', 'not_equal', 'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal' ],
        DataItem::TYPE_STRING  => [ 'equal', 'not_equal', 'contains', 'not_contains', 'starts_with', 'ends_with', 'is_empty', 'is_not_empty' ],
        DataItem::TYPE_ENUM    => [ 'is', 'is_not', 'is_any_of' ],
        DataItem::TYPE_DATE    => [ 'before', 'after', 'in_last', 'not_in_last', 'is_set', 'is_not_set' ],
        DataItem::TYPE_BOOLEAN => [ 'is_true', 'is_false' ],
        DataItem::TYPE_LIST    => [ 'matches_any', 'matches_all', 'matches_none' ],
        DataItem::TYPE_META    => [ 'equal', 'not_equal', 'contains', 'not_contains', 'starts_with', 'ends_with', 'is_empty', 'is_not_empty', 'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal' ],
    ];

    /** Fixed lengths, like Wait: seconds per unit of a relative date. */
    private const UNIT_SECONDS = [
        'hours' => 3600,
        'days'  => 86400,
    ];

    /** Operators whose value is a list. */
    private const LIST_VALUE = [ 'matches_any', 'matches_all', 'matches_none', 'is_any_of' ];

    /** Operators whose value is an amount and a unit. */
    private const RELATIVE = [ 'in_last', 'not_in_last' ];

    private const NUMERIC_ONLY = [ 'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal' ];

    /**
     * How each operator reads in a rule ("Order total is greater than 500").
     *
     * @return array<string, string> Operator id => label.
     */
    public static function labels(): array {
        return [
            'equal'            => __( 'is equal to', 'yaymatik' ),
            'not_equal'        => __( 'is not equal to', 'yaymatik' ),
            'greater_than'     => __( 'is greater than', 'yaymatik' ),
            'greater_or_equal' => __( 'is greater than or equal to', 'yaymatik' ),
            'less_than'        => __( 'is less than', 'yaymatik' ),
            'less_or_equal'    => __( 'is less than or equal to', 'yaymatik' ),
            'contains'         => __( 'contains', 'yaymatik' ),
            'not_contains'     => __( 'does not contain', 'yaymatik' ),
            'starts_with'      => __( 'starts with', 'yaymatik' ),
            'ends_with'        => __( 'ends with', 'yaymatik' ),
            'is_empty'         => __( 'is empty', 'yaymatik' ),
            'is_not_empty'     => __( 'is not empty', 'yaymatik' ),
            'is'               => __( 'is', 'yaymatik' ),
            'is_not'           => __( 'is not', 'yaymatik' ),
            'before'           => __( 'is before', 'yaymatik' ),
            'after'            => __( 'is after', 'yaymatik' ),
            'is_true'          => __( 'is true', 'yaymatik' ),
            'is_false'         => __( 'is false', 'yaymatik' ),
            'matches_any'      => __( 'matches any of', 'yaymatik' ),
            'matches_all'      => __( 'matches all of', 'yaymatik' ),
            'matches_none'     => __( 'matches none of', 'yaymatik' ),
            'is_any_of'        => __( 'is any of', 'yaymatik' ),
            'in_last'          => __( 'is in the last', 'yaymatik' ),
            'not_in_last'      => __( 'is not in the last', 'yaymatik' ),
            'is_set'           => __( 'is set', 'yaymatik' ),
            'is_not_set'       => __( 'is not set', 'yaymatik' ),
        ];
    }

    /** Operators that compare against nothing. */
    private const NO_VALUE = [ 'is_empty', 'is_not_empty', 'is_true', 'is_false', 'is_set', 'is_not_set' ];

    public static function supports( string $type, string $operator ): bool {
        return in_array( $operator, self::BY_TYPE[ $type ] ?? [], true );
    }

    public static function needs_value( string $operator ): bool {
        return ! in_array( $operator, self::NO_VALUE, true );
    }

    public static function takes_list( string $operator ): bool {
        return in_array( $operator, self::LIST_VALUE, true );
    }

    public static function is_relative( string $operator ): bool {
        return in_array( $operator, self::RELATIVE, true );
    }

    /** @return string[] */
    public static function units(): array {
        return array_keys( self::UNIT_SECONDS );
    }

    /**
     * Whether a Unix time falls in the last N hours or days. No time never matches.
     *
     * @param int|null $at
     * @param int      $amount
     * @param string   $unit
     * @param int      $now
     */
    public static function in_last( ?int $at, int $amount, string $unit, int $now ): bool {
        return null !== $at && $at <= $now && $now - $amount * self::UNIT_SECONDS[ $unit ] <= $at;
    }

    /**
     * Whether a Unix time is older than the last N hours or days. No time never matches.
     *
     * @param int|null $at
     * @param int      $amount
     * @param string   $unit
     * @param int      $now
     */
    public static function not_in_last( ?int $at, int $amount, string $unit, int $now ): bool {
        return null !== $at && $at < $now - $amount * self::UNIT_SECONDS[ $unit ];
    }

    /**
     * @param string $type
     * @param string $operator Supported by the type.
     * @param mixed  $actual   Float for numbers, `Y-m-d` for dates, bool for booleans, string[] for lists, string otherwise.
     * @param mixed  $expected The rule's value, or another field's value.
     */
    public static function compare( string $type, string $operator, $actual, $expected ): bool {
        if ( DataItem::TYPE_LIST === $type ) {
            return self::compare_lists( $operator, self::lowered( $actual ), self::lowered( $expected ) );
        }
        if ( 'is_any_of' === $operator ) {
            return in_array( strtolower( trim( (string) $actual ) ), self::lowered( $expected ), true );
        }
        if ( DataItem::TYPE_META === $type ) {
            $numeric = is_numeric( $actual ) && is_numeric( $expected );
            if ( in_array( $operator, self::NUMERIC_ONLY, true ) ) {
                return $numeric && self::compare_numbers( $operator, (float) $actual, (float) $expected );
            }
            // "5.0" and "5" are the same number.
            if ( $numeric && ( 'equal' === $operator || 'not_equal' === $operator ) ) {
                return self::compare_numbers( $operator, (float) $actual, (float) $expected );
            }
        }
        if ( DataItem::TYPE_NUMBER === $type ) {
            return self::compare_numbers( $operator, (float) $actual, (float) $expected );
        }
        if ( DataItem::TYPE_BOOLEAN === $type ) {
            $true = self::is_true( $actual );
            return 'is_true' === $operator ? $true : ( 'is_false' === $operator && ! $true );
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
            case 'is_not_set':
                return '' === trim( $actual );
            case 'is_not_empty':
            case 'is_set':
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
        }
        return false;
    }

    /**
     * @param mixed $values
     * @return string[] Lowercased and trimmed.
     */
    private static function lowered( $values ): array {
        return array_map( fn( $value ) => strtolower( trim( (string) $value ) ), (array) $values );
    }

    /**
     * An empty list matches none, and neither any nor all.
     *
     * @param string   $operator
     * @param string[] $actual
     * @param string[] $expected
     */
    private static function compare_lists( string $operator, array $actual, array $expected ): bool {
        $shared = array_intersect( $expected, $actual );
        switch ( $operator ) {
            case 'matches_any':
                return (bool) $shared;
            case 'matches_all':
                return $actual && count( array_unique( $shared ) ) === count( array_unique( $expected ) );
            case 'matches_none':
                return ! $shared;
        }
        return false;
    }

    /**
     * Stored flags come as bools, `1` / `0` or `yes` / `no`.
     *
     * @param mixed $value
     */
    private static function is_true( $value ): bool {
        return is_bool( $value ) ? $value : is_scalar( $value ) && in_array( strtolower( trim( (string) $value ) ), [ '1', 'yes', 'true', 'on' ], true );
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
