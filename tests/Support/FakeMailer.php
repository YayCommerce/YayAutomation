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

    public function wrap_message( $heading, $message ) {
        // WooCommerce's email-header.php escapes the heading.
        return '<h1>' . esc_html( $heading ) . "</h1>{$message}";
    }

    public function send( $to, $subject, $body, $headers = '' ) {
        $this->sent[]    = [ $to, $subject, $body ];
        $this->headers[] = $headers;
        return $this->succeeds;
    }
}
