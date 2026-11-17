<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Api\CustomerView;
use YayMatik\Workflow\DataItems\CustomerData;

defined( 'ABSPATH' ) || exit;

/**
 * Any customer object core loads (`WC_Order`, `WC_Customer`, `CartCustomer`, a reviewer's
 * `EmailCustomer`) as the public `CustomerView`.
 *
 * @internal
 */
final class CustomerViewAdapter implements CustomerView {

    /** @var object */
    private $customer;

    /**
     * @param object $customer
     */
    public function __construct( $customer ) {
        $this->customer = $customer;
    }

    public function email(): string {
        return (string) $this->customer->get_billing_email();
    }

    public function user_id(): int {
        return CustomerData::user_id( $this->customer );
    }

    public function first_name(): string {
        return (string) $this->customer->get_billing_first_name();
    }

    public function last_name(): string {
        return (string) $this->customer->get_billing_last_name();
    }

    public function order_id(): int {
        return $this->customer instanceof \WC_Order ? (int) $this->customer->get_id() : 0;
    }
}
