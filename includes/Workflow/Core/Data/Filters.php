<?php
namespace YayMatik\Workflow\Core\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Template filters, by name: `{{ order.created_date | date: "F j" }}`. Each filter declares the
 * value types it takes, so the validator can flag `{{ order.total | upper }}` before it runs.
 * A filter never fails: input it can't use comes back unchanged.
 */
final class Filters {

    public const TYPE_ANY = 'any';

    /** @var array[] name => [ 'input' => string[], 'apply' => callable( mixed $value, array $args ): mixed ] */
    private $filters = [];

    /** @var callable fn( int $timestamp, string $format ): string. Empty format = the site's date format. */
    private $format_date;

    /** @var callable fn( float $amount, string $currency ): string. Plain text. Empty currency = the store's. */
    private $format_money;

    /** @var \DateTimeZone|null Where date strings without an offset are read; null = the site's. */
    private $timezone;

    /**
     * @param callable|null      $format_date  Date formatter; null uses WordPress.
     * @param callable|null      $format_money Money formatter; null uses WooCommerce.
     * @param \DateTimeZone|null $timezone     Timezone for date strings without an offset; null = the site's.
     */
    public function __construct( ?callable $format_date = null, ?callable $format_money = null, ?\DateTimeZone $timezone = null ) {
        $this->format_date  = $format_date ?? [ self::class, 'wp_date' ];
        $this->format_money = $format_money ?? [ self::class, 'wc_money' ];
        $this->timezone     = $timezone;

        $this->add( 'default', [ self::TYPE_ANY ], fn( $value, array $args ) => self::is_empty( $value ) ? ( $args[0] ?? '' ) : $value );
        $this->add( 'upper', [ 'string' ], fn( $value ) => is_string( $value ) ? mb_strtoupper( $value ) : $value );
        $this->add( 'lower', [ 'string' ], fn( $value ) => is_string( $value ) ? mb_strtolower( $value ) : $value );
        $this->add( 'join', [ 'list' ], fn( $value, array $args ) => is_array( $value ) ? self::join( $value, (string) ( $args[0] ?? ', ' ) ) : $value );
        $this->add( 'date', [ 'date', 'datetime' ], [ $this, 'date' ] );
        $this->add( 'money', [ 'number', 'money' ], [ $this, 'money' ] );
    }

    /**
     * Registers a filter. Yay plugins may add their own later.
     *
     * @param string   $name  Filter name used in templates.
     * @param string[] $input Value types it takes (`any`, `string`, `number`, `money`, `date`, `datetime`, `list`, …).
     * @param callable $apply fn( mixed $value, array $args ): mixed.
     */
    public function add( string $name, array $input, callable $apply ): void {
        $this->filters[ $name ] = [
            'input' => $input,
            'apply' => $apply,
        ];
    }

    /** Registered filter names, in registration order. */
    public function names(): array {
        return array_keys( $this->filters );
    }

    public function has( string $name ): bool {
        return isset( $this->filters[ $name ] );
    }

    /**
     * Value types a filter takes, or null for an unknown filter.
     *
     * @param string $name Filter name.
     */
    public function input( string $name ): ?array {
        return $this->filters[ $name ]['input'] ?? null;
    }

    /**
     * Runs a filter. An unknown filter returns the value unchanged.
     *
     * @param string $name  Filter name.
     * @param mixed  $value Input value.
     * @param array  $args  Arguments from the template.
     * @return mixed
     */
    public function apply( string $name, $value, array $args ) {
        return $this->has( $name ) ? ( $this->filters[ $name ]['apply'] )( $value, $args ) : $value;
    }

    /**
     * `date: "format"`. Takes a timestamp, a date string or a DateTimeInterface. A string without an
     * offset (`Y-m-d`, `Y-m-d H:i:s`) is read in the site's timezone, so a date keeps its day.
     *
     * @param mixed $value Date.
     * @param array $args  [ format ].
     * @return mixed
     */
    public function date( $value, array $args ) {
        $timestamp = $this->timestamp( $value );
        return null === $timestamp ? $value : ( $this->format_date )( $timestamp, (string) ( $args[0] ?? '' ) );
    }

    /**
     * `money`. Takes a number, or a money value `[ 'amount' => …, 'currency' => … ]`.
     *
     * @param mixed $value Amount.
     * @return mixed
     */
    public function money( $value ) {
        if ( is_array( $value ) && isset( $value['amount'] ) && is_numeric( $value['amount'] ) ) {
            return ( $this->format_money )( (float) $value['amount'], (string) ( $value['currency'] ?? '' ) );
        }
        return is_numeric( $value ) ? ( $this->format_money )( (float) $value, '' ) : $value;
    }

    /**
     * Nothing to show: null, blank text or an empty list.
     *
     * @param mixed $value Value.
     */
    public static function is_empty( $value ): bool {
        return null === $value || [] === $value || ( is_string( $value ) && '' === trim( $value ) );
    }

    /**
     * @param array  $items     Values.
     * @param string $separator Between items.
     */
    private static function join( array $items, string $separator ): string {
        $items = array_filter( $items, fn( $item ) => is_scalar( $item ) && '' !== (string) $item );
        return implode( $separator, array_map( 'strval', $items ) );
    }

    /**
     * Unix time of a date. Timestamps are taken as they are; strings without an offset are read in
     * the site's timezone (a string with its own offset keeps it).
     *
     * @param mixed $value Timestamp, date string or DateTimeInterface.
     */
    private function timestamp( $value ): ?int {
        if ( $value instanceof \DateTimeInterface ) {
            return $value->getTimestamp();
        }
        if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
            return (int) $value;
        }
        if ( ! is_string( $value ) || '' === trim( $value ) ) {
            return null;
        }
        try {
            return ( new \DateTimeImmutable( $value, $this->timezone ?? wp_timezone() ) )->getTimestamp();
        } catch ( \Exception $e ) {
            return null;
        }
    }

    /**
     * @param int    $timestamp Unix time.
     * @param string $format    PHP date format; empty = the site's.
     */
    private static function wp_date( int $timestamp, string $format ): string {
        return (string) wp_date( '' !== $format ? $format : (string) get_option( 'date_format' ), $timestamp );
    }

    /**
     * @param float  $amount   Amount.
     * @param string $currency ISO code; empty = the store's.
     */
    private static function wc_money( float $amount, string $currency ): string {
        if ( ! function_exists( 'wc_price' ) ) {
            return (string) $amount;
        }
        $price = wc_price( $amount, '' !== $currency ? [ 'currency' => $currency ] : [] );
        return html_entity_decode( wp_strip_all_tags( $price ), ENT_QUOTES, 'UTF-8' );
    }
}
