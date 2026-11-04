<?php
namespace YayMatik\Tests\Support;

/** A `WC_Order` that records what steps do to it. Load woocommerce-stubs.php first. */
final class FakeOrder extends \WC_Order {

    /** @var FakeOrder[] What `wc_get_order()` finds, by id. */
    public static $found = [];

    /** @var array[] [ note, is_customer_note ]. */
    public $notes = [];

    /** @var array[] [ to, note ]. */
    public $status_changes = [];

    /** @var array Meta key => value. */
    public $meta = [];

    /** @var int */
    public $saves = 0;

    public function get_status() {
        return $this->data['status'] ?? 'pending';
    }

    public function add_order_note( $note, $is_customer_note = 0 ) {
        $this->notes[] = [ $note, $is_customer_note ];
        return count( $this->notes );
    }

    public function update_status( $to, $note = '' ) {
        $this->status_changes[] = [ $to, $note ];
        $this->data['status']   = $to;
        return true;
    }

    public function get_meta( $key = '', $single = true, $context = 'view' ) {
        return $this->meta[ $key ] ?? '';
    }

    public function meta_exists( $key = '' ) {
        return isset( $this->meta[ $key ] );
    }

    public function update_meta_data( $key, $value ) {
        $this->meta[ $key ] = $value;
    }

    public function save() {
        ++$this->saves;
        return $this->data['id'] ?? 0;
    }
}
