<?php
namespace YayMatik\Tests\Support;

/** WooCommerce's mailer, recording what it sends. `WC()->mailer()` returns `FakeMailer::$current`. */
final class FakeMailer {

    /** @var FakeMailer|null */
    public static $current = null;

    /** @var bool What send() returns. */
    public $succeeds = true;

    /** @var array[] [ to, subject, body ]. */
    public $sent = [];

    /** @var string[] Headers of each send, in order; '' when none were passed. */
    public $headers = [];

    /** @var bool send() goes on as WooCommerce and `wp_mail()` do: their filters, then `wp_mail_succeeded` or `wp_mail_failed`. */
    public $through_wp_mail = false;

    /** @var array[] With `through_wp_mail`: what `wp_mail()` got, after its filters. */
    public $mails = [];

    /** @var callable|null Called inside send(), e.g. to read the filters set while sending. */
    public $on_send = null;

    /** @var FakeWcEmail[] WooCommerce's emails, keyed by class name. */
    public $emails = [];

    public function get_emails() {
        return $this->emails;
    }

    public function wrap_message( $heading, $message ) {
        // WooCommerce's email-header.php escapes the heading.
        return '<h1>' . esc_html( $heading ) . "</h1>{$message}";
    }

    public function send( $to, $subject, $body, $headers = '' ) {
        $this->sent[]    = [ $to, $subject, $body ];
        $this->headers[] = $headers;
        if ( $this->on_send ) {
            ( $this->on_send )();
        }
        if ( $this->through_wp_mail ) {
            $atts          = apply_filters(
                'wp_mail',
                [
                    'to'          => $to,
                    'subject'     => $subject,
                    'message'     => apply_filters( 'woocommerce_mail_content', $body ),
                    'headers'     => $headers,
                    'attachments' => [],
                ]
            );
            $this->mails[] = $atts;
            // As `wp_mail()` passes them on: its custom headers, parsed to name => value.
            $parsed = [];
            foreach ( explode( "\n", str_replace( "\r\n", "\n", (string) $atts['headers'] ) ) as $line ) {
                $parts = explode( ':', $line, 2 );
                if ( 2 === count( $parts ) && 0 !== strcasecmp( trim( $parts[0] ), 'Content-Type' ) ) {
                    $parsed[ trim( $parts[0] ) ] = trim( $parts[1] );
                }
            }
            $atts['headers'] = $parsed;
            if ( $this->succeeds ) {
                do_action( 'wp_mail_succeeded', $atts );
            } else {
                do_action( 'wp_mail_failed', new \WP_Error( 'wp_mail_failed', 'SMTP connect() failed.', $atts ) );
            }
        }
        return $this->succeeds;
    }
}
