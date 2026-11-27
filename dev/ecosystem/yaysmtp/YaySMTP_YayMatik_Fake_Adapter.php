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
}
