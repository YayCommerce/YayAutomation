<?php
namespace YayAutomation\Tests\Support;

/** WooCommerce's mailer, recording what it sends. `WC()->mailer()` returns `FakeMailer::$current`. */
final class FakeMailer {

    /** @var FakeMailer|null */
    public static $current = null;

    /** @var bool What send() returns. */
    public $succeeds = true;

    /** @var array[] [ to, subject, body ]. */
    public $sent = [];

    /**
     * @param string $heading Heading.
     * @param string $message Body HTML.
     */
    public function wrap_message( $heading, $message ) {
        return "<h1>{$heading}</h1>{$message}";
    }

    /**
     * @param string[] $to      Recipients.
     * @param string   $subject Subject.
     * @param string   $body    Body.
     */
    public function send( $to, $subject, $body ) {
        $this->sent[] = [ $to, $subject, $body ];
        return $this->succeeds;
    }
}
