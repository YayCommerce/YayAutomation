<?php
namespace YayMatik\Workflow\Core\Storage;

defined( 'ABSPATH' ) || exit;

/** Plugin settings in one autoloaded option, keys in snake_case. */
final class Settings {

    private const OPTION = 'yaymatik_settings';

    private const TEXT_MAX = 1000;

    /** Integer settings: [ min, max ]. */
    private const RANGES = [
        'abandon_minutes'     => [ 15, 1440 ],
        'cart_retention_days' => [ 7, 365 ],
        'run_retention_days'  => [ 7, 730 ],
    ];

    public static function defaults(): array {
        return [
            'cart_tracking'       => false,
            'abandon_minutes'     => 60,
            'capture_guest_email' => true,
            'cart_retention_days' => 60,
            'run_retention_days'  => 90,
            'unsubscribe_text'    => '',
        ];
    }

    /** @var array|null Built once per request: store-front hooks read it on every request. */
    private static $all = null;

    /** Stored values over the defaults; anything stored out of shape is read clamped or cast. */
    public static function all(): array {
        if ( null !== self::$all ) {
            return self::$all;
        }
        $stored = get_option( self::OPTION, [] );
        $stored = is_array( $stored ) ? $stored : [];
        $values = [];
        foreach ( self::defaults() as $key => $default ) {
            $value          = array_key_exists( $key, $stored ) ? $stored[ $key ] : $default;
            $values[ $key ] = self::read( $key, $value, $default );
        }
        self::$all = $values;
        return $values;
    }

    /**
     * @param string $key
     * @return mixed Null for an unknown key.
     */
    public static function get( string $key ) {
        return self::all()[ $key ] ?? null;
    }

    /**
     * Saves only when every given value is valid; unknown keys are ignored.
     *
     * @param array $changes
     * @return array|\WP_Error All settings after the save, or errors[{ field, message }] with status 400.
     */
    public static function update( array $changes ) {
        self::$all = null;
        $values    = self::all();
        $errors    = [];
        foreach ( array_intersect_key( $changes, $values ) as $key => $value ) {
            $checked = self::check( $key, $value );
            if ( $checked instanceof \WP_Error ) {
                $errors[] = [
                    'field'   => $key,
                    'message' => $checked->get_error_message(),
                ];
                continue;
            }
            $values[ $key ] = $checked;
        }
        if ( $errors ) {
            return new \WP_Error(
                'yaymatik_invalid_settings',
                __( 'Fix the highlighted settings.', 'yaymatik' ),
                [
                    'status' => 400,
                    'errors' => $errors,
                ]
            );
        }
        update_option( self::OPTION, $values, true );
        self::$all = null;
        return self::all();
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @param mixed  $default_value
     * @return mixed
     */
    private static function read( string $key, $value, $default_value ) {
        if ( is_bool( $default_value ) ) {
            return (bool) $value;
        }
        if ( isset( self::RANGES[ $key ] ) ) {
            if ( ! is_numeric( $value ) ) {
                return $default_value;
            }
            list( $min, $max ) = self::RANGES[ $key ];
            return max( $min, min( $max, (int) $value ) );
        }
        return self::plain_text( is_string( $value ) ? $value : '' );
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @return mixed|\WP_Error The value to store.
     */
    private static function check( string $key, $value ) {
        if ( is_bool( self::defaults()[ $key ] ) ) {
            return is_bool( $value ) ? $value : new \WP_Error( 'invalid', __( 'Choose on or off.', 'yaymatik' ) );
        }
        if ( isset( self::RANGES[ $key ] ) ) {
            list( $min, $max ) = self::RANGES[ $key ];
            return is_int( $value ) && $value >= $min && $value <= $max
                ? $value
                /* translators: 1: smallest number, 2: largest number */
                : new \WP_Error( 'invalid', sprintf( __( 'Enter a whole number from %1$d to %2$d.', 'yaymatik' ), $min, $max ) );
        }
        if ( ! is_string( $value ) ) {
            return new \WP_Error( 'invalid', __( 'Enter text.', 'yaymatik' ) );
        }
        return self::plain_text( $value );
    }

    /**
     * Stored as typed (no entities, `%` kept), escaped on output.
     *
     * @param string $value
     */
    private static function plain_text( string $value ): string {
        $value = wp_strip_all_tags( wp_check_invalid_utf8( $value ) );
        return mb_substr( trim( str_replace( "\r\n", "\n", $value ) ), 0, self::TEXT_MAX );
    }
}
