<?php
namespace YayMatik\Workflow\Core\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Template filters by name. A filter never fails: input it can't use comes back unchanged.
 */
final class Filters {

    /** @var callable[] name => fn( mixed $value, array $args ): mixed */
    private $filters = [];

    /** @var callable fn( int $timestamp, string $format ): string; empty format = the site's */
    private $format_date;

    /** @var callable fn( float $amount, string $currency ): string, plain text; empty currency = the store's */
    private $format_money;

    /** @var \DateTimeZone|null */
    private $timezone;

    /**
     * @param callable|null      $format_date  Null = WordPress.
     * @param callable|null      $format_money Null = WooCommerce.
     * @param \DateTimeZone|null $timezone     For date strings without an offset; null = the site's.
     */
    public function __construct( ?callable $format_date = null, ?callable $format_money = null, ?\DateTimeZone $timezone = null ) {
        $this->format_date  = $format_date ?? [ self::class, 'wp_date' ];
        $this->format_money = $format_money ?? [ self::class, 'wc_money' ];
        $this->timezone     = $timezone;

        $this->add( 'default', fn( $value, array $args ) => self::is_empty( $value ) ? ( $args[0] ?? '' ) : $value );
        $this->add( 'upper', fn( $value ) => is_string( $value ) ? mb_strtoupper( $value ) : $value );
        $this->add( 'lower', fn( $value ) => is_string( $value ) ? mb_strtolower( $value ) : $value );
        $this->add( 'join', fn( $value, array $args ) => is_array( $value ) ? self::join( $value, (string) ( $args[0] ?? ', ' ) ) : $value );
        $this->add( 'date', [ $this, 'date' ] );
        $this->add( 'money', [ $this, 'money' ] );
    }

    /**
     * @param string   $name
     * @param callable $apply fn( mixed $value, array $args ): mixed.
     */
    public function add( string $name, callable $apply ): void {
        $this->filters[ $name ] = $apply;
    }

    /** In registration order. */
    public function names(): array {
        return array_keys( $this->filters );
    }

    public function has( string $name ): bool {
        return isset( $this->filters[ $name ] );
    }

    /**
     * Runs a filter; an unknown one returns the value unchanged.
     *
     * @param string $name
     * @param mixed  $value
     * @param array  $args
     * @return mixed
     */
    public function apply( string $name, $value, array $args ) {
        return $this->has( $name ) ? ( $this->filters[ $name ] )( $value, $args ) : $value;
    }

    /**
     * `date: "format"`. Takes a timestamp, a date string or a DateTimeInterface.
     *
     * @param mixed $value
     * @param array $args  [ format ].
     * @return mixed
     */
    public function date( $value, array $args ) {
        $timestamp = $this->timestamp( $value );
        return null === $timestamp ? $value : ( $this->format_date )( $timestamp, (string) ( $args[0] ?? '' ) );
    }

    /**
     * `money`. Takes a number, or `[ 'amount' => …, 'currency' => … ]`.
     *
     * @param mixed $value
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
     * @param mixed $value
     */
    public static function is_empty( $value ): bool {
        return null === $value || [] === $value || ( is_string( $value ) && '' === trim( $value ) );
    }

    /**
     * @param array  $items
     * @param string $separator
     */
    private static function join( array $items, string $separator ): string {
        $items = array_filter( $items, fn( $item ) => is_scalar( $item ) && '' !== (string) $item );
        return implode( $separator, array_map( 'strval', $items ) );
    }

    /**
     * Unix time of a date.
     *
     * @param mixed $value
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
        // Without an offset (`Y-m-d`, `Y-m-d H:i:s`) it's read in the site's timezone, so a date keeps its day.
        try {
            return ( new \DateTimeImmutable( $value, $this->timezone ?? wp_timezone() ) )->getTimestamp();
        } catch ( \Exception $e ) {
            return null;
        }
    }

    /**
     * @param int    $timestamp
     * @param string $format    Empty = the site's.
     */
    private static function wp_date( int $timestamp, string $format ): string {
        return (string) wp_date( '' !== $format ? $format : (string) get_option( 'date_format' ), $timestamp );
    }

    /**
     * @param float  $amount
     * @param string $currency Empty = the store's.
     */
    private static function wc_money( float $amount, string $currency ): string {
        if ( ! function_exists( 'wc_price' ) ) {
            return (string) $amount;
        }
        $price = wc_price( $amount, '' !== $currency ? [ 'currency' => $currency ] : [] );
        return html_entity_decode( wp_strip_all_tags( $price ), ENT_QUOTES, 'UTF-8' );
    }
}
