<?php
namespace YayMatik\Tests\Support;

/** A `WC_Email` that records the order ids it was triggered for. */
final class FakeWcEmail {

    /** @var string */
    public $id;

    /** @var string */
    private $title;

    /** @var bool */
    private $customer;

    /** @var bool */
    public $enabled = true;

    /** @var int[] */
    public $triggered = [];

    /**
     * @param string $id
     * @param string $title
     * @param bool   $customer
     */
    public function __construct( string $id, string $title, bool $customer = true ) {
        $this->id       = $id;
        $this->title    = $title;
        $this->customer = $customer;
    }

    public function get_title() {
        return $this->title;
    }

    public function is_customer_email() {
        return $this->customer;
    }

    public function is_enabled() {
        return $this->enabled;
    }

    public function trigger( $order_id ) {
        $this->triggered[] = $order_id;
    }
}
