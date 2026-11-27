<?php
/**
 * YaySMTP's fake adapter: a fixed email log, no YaySMTP code.
 *
 * @package YaySMTP
 */

defined( 'ABSPATH' ) || exit;

/** Stands in for YaySMTP until its real adapter is written. */
final class YaySMTP_YayMatik_Fake_Adapter {

    /** Log entry id => entry. */
    private const LOG = [
        '1' => [
            'to'      => 'alex@example.com',
            'subject' => 'Your order is on its way',
            'error'   => 'SMTP connect() failed.',
        ],
    ];

    /** Its log of sent emails, oldest first: `to`, `subject`, `mark`. */
    public const SENT_LOG = 'yaysmtp_fake_sent_log';

    /** YaySMTP's own behavior: it logs every email it sends, and tells YayMatik about the ones YayMatik marked. */
    public function hook_mail(): void {
        add_action( 'wp_mail_succeeded', [ $this, 'log_mail' ] );
    }

    /**
     * @param array $mail
     */
    public function log_mail( $mail ) {
        $mark  = self::yaymatik_mark( $mail['headers'] ?? '' );
        $log   = (array) get_option( self::SENT_LOG, [] );
        $log[] = [
            'to'      => implode( ', ', (array) ( $mail['to'] ?? [] ) ),
            'subject' => (string) ( $mail['subject'] ?? '' ),
            'mark'    => $mark,
        ];
        update_option( self::SENT_LOG, $log, false );
        if ( '' !== $mark ) {
            do_action( 'yaymatik_email_status', $mark, 'delivered' );
        }
    }

    /**
     * A failed email's log entry; null when it's gone.
     *
     * @param string $id
     */
    public function load_email( string $id ) {
        return isset( self::LOG[ $id ] ) ? (object) self::LOG[ $id ] : null;
    }

    /**
     * @param object $email
     */
    public function email_to( $email ): string {
        return $email->to;
    }

    /**
     * @param object $email
     */
    public function email_subject( $email ): string {
        return $email->subject;
    }

    /**
     * @param object $email
     */
    public function email_error( $email ): string {
        return $email->error;
    }

    /**
     * Log entries sent to this address, as run subjects.
     *
     * @param string $email
     * @return string[]
     */
    public function privacy_subjects( string $email ): array {
        $ids = array_keys( array_filter( self::LOG, fn( array $entry ) => strtolower( $email ) === $entry['to'] ) );
        return array_map( fn( $id ) => 'yaysmtp_email:' . $id, $ids );
    }

    /**
     * The `X-YayMatik-Mark` header in `wp_mail()` headers: a string, lines, or after sending its parsed name => value array.
     *
     * @param mixed $headers
     */
    private static function yaymatik_mark( $headers ): string {
        $lines = is_array( $headers ) ? $headers : explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
        foreach ( $lines as $name => $line ) {
            $parts = is_string( $name ) ? [ $name, $line ] : explode( ':', (string) $line, 2 );
            if ( 2 === count( $parts ) && 0 === strcasecmp( trim( $parts[0] ), 'X-YayMatik-Mark' ) ) {
                return trim( (string) $parts[1] );
            }
        }
        return '';
    }
}
