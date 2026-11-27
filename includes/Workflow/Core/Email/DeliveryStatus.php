<?php
namespace YayMatik\Workflow\Core\Email;

use YayMatik\Workflow\Core\Storage\RunStepStore;

defined( 'ABSPATH' ) || exit;

/**
 * The mark on every email YayMatik sends, and the furthest delivery status of a marked email as its step's `output.delivery`.
 */
final class DeliveryStatus {

    public const HEADER = 'X-YayMatik-Mark';

    /** Send test's mark: no step to record on. */
    public const TEST = 'test';

    public const SENT      = 'sent';
    public const FAILED    = 'failed';
    public const DELIVERED = 'delivered';
    public const OPENED    = 'opened';
    public const CLICKED   = 'clicked';
    public const BOUNCED   = 'bounced';

    public const STATUSES = [ self::SENT, self::FAILED, self::DELIVERED, self::OPENED, self::CLICKED, self::BOUNCED ];

    /** How far an email got: a report replaces only one further back, so a late webhook can't undo a later stage. Failed and bounced are final. */
    private const STAGE = [
        self::SENT      => 0,
        self::DELIVERED => 1,
        self::OPENED    => 2,
        self::CLICKED   => 3,
        self::FAILED    => 4,
        self::BOUNCED   => 4,
    ];

    /** @var array Run-step row id => its delivery or null, for the steps running now: their row is written when they finish. */
    private static $running = [];

    /** @var callable fn( int $row, array $delivery ): bool */
    private static $save = [ RunStepStore::class, 'set_delivery' ];

    public static function register(): void {
        add_action( 'wp_mail_succeeded', [ self::class, 'on_sent' ] );
        add_action( 'wp_mail_failed', [ self::class, 'on_failed' ] );
        add_action( 'yaymatik_email_status', [ self::class, 'record' ], 10, 3 );
    }

    /**
     * @param string $mark
     */
    public static function header( string $mark ): string {
        return self::HEADER . ': ' . $mark . "\r\n";
    }

    /**
     * The mark in `wp_mail()` headers: a string, lines, or after sending its parsed name => value array; '' when there's none.
     *
     * @param mixed $headers
     */
    public static function mark_of( $headers ): string {
        $lines = is_array( $headers ) ? $headers : explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
        foreach ( $lines as $name => $line ) {
            $parts = is_string( $name ) ? [ $name, $line ] : explode( ':', (string) $line, 2 );
            if ( 2 === count( $parts ) && is_scalar( $parts[1] ) && 0 === strcasecmp( trim( $parts[0] ), self::HEADER ) ) {
                return trim( (string) $parts[1] );
            }
        }
        return '';
    }

    /**
     * A step starts: a status reported while it runs waits for `finish()`.
     *
     * @param int $row
     */
    public static function start( int $row ): void {
        self::$running[ $row ] = null;
    }

    /**
     * A step ends: the status reported while it ran, if any.
     *
     * @param int $row
     */
    public static function finish( int $row ): ?array {
        $delivery = self::$running[ $row ] ?? null;
        unset( self::$running[ $row ] );
        return $delivery;
    }

    /**
     * `wp_mail_succeeded`, with `wp_mail()`'s arguments; any other plugin may fire it too.
     *
     * @param mixed $mail
     */
    public static function on_sent( $mail ): void {
        self::record_own( self::mark_of( is_array( $mail ) ? $mail['headers'] ?? '' : '' ), self::SENT );
    }

    /**
     * `wp_mail_failed`, its error data `wp_mail()`'s arguments; any other plugin may fire it too.
     *
     * @param mixed $error
     */
    public static function on_failed( $error ): void {
        $data = is_wp_error( $error ) ? $error->get_error_data() : null;
        self::record_own( self::mark_of( is_array( $data ) ? $data['headers'] ?? '' : '' ), self::FAILED, is_wp_error( $error ) ? $error->get_error_message() : '' );
    }

    /**
     * Core's own sent / failed, for the step sending now only: never over a mail plugin's report, nor on a resend.
     *
     * @param string $mark
     * @param string $status
     * @param string $reason
     */
    private static function record_own( string $mark, string $status, string $reason = '' ): void {
        $row = ctype_digit( $mark ) ? (int) $mark : 0;
        if ( ! $row || ! array_key_exists( $row, self::$running ) || null !== self::$running[ $row ] ) {
            return;
        }
        self::record( $mark, $status, $reason );
    }

    /**
     * `yaymatik_email_status` ( mark, one of `STATUSES`, why it failed or bounced ): kept when it `replaces()` the one before.
     *
     * @param mixed $mark
     * @param mixed $status
     * @param mixed $reason
     */
    public static function record( $mark, $status, $reason = '' ): void {
        $row = is_scalar( $mark ) && ctype_digit( (string) $mark ) ? (int) $mark : 0;
        if ( ! $row || ! in_array( $status, self::STATUSES, true ) ) {
            return;
        }
        $delivery = [
            'status' => $status,
            'at'     => gmdate( 'c' ),
        ];
        if ( is_string( $reason ) && '' !== $reason ) {
            $delivery['reason'] = $reason;
        }
        if ( array_key_exists( $row, self::$running ) ) {
            if ( self::replaces( $status, self::$running[ $row ] ) ) {
                self::$running[ $row ] = $delivery;
            }
            return;
        }
        ( self::$save )( $row, $delivery );
    }

    /**
     * Whether a status reported now replaces the delivery stored before.
     *
     * @param string     $status
     * @param array|null $before
     */
    public static function replaces( string $status, ?array $before ): bool {
        $was = self::STAGE[ $before['status'] ?? '' ] ?? -1;
        return self::STAGE[ $status ] > $was;
    }
}
