<?php
namespace YayMatik\Workflow\Core\Data;

defined( 'ABSPATH' ) || exit;

/** A person known only by an email address, with the billing getters `CustomerData` reads: a guest reviewer. */
final class EmailCustomer {

    /** @var string */
    private $email;

    /**
     * @param string $email
     */
    public function __construct( string $email ) {
        $this->email = $email;
    }

    public function get_id(): int {
        return 0;
    }

    public function get_billing_email(): string {
        return $this->email;
    }

    public function get_billing_first_name(): string {
        return '';
    }

    public function get_billing_last_name(): string {
        return '';
    }

    public function get_billing_phone(): string {
        return '';
    }

    public function get_billing_country(): string {
        return '';
    }
}
