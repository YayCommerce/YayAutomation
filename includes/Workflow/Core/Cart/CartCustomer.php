<?php
namespace YayMatik\Workflow\Core\Cart;

defined( 'ABSPATH' ) || exit;

/** The person behind a cart, with the billing getters `CustomerData` reads. */
final class CartCustomer {

    /** @var SavedCart */
    private $cart;

    /** @var \WC_Customer|null */
    private $account;

    /**
     * @param SavedCart         $cart
     * @param \WC_Customer|null $account
     */
    public function __construct( SavedCart $cart, ?\WC_Customer $account ) {
        $this->cart    = $cart;
        $this->account = $account;
    }

    /**
     * @param SavedCart $cart
     */
    public static function of( SavedCart $cart ): self {
        $user_id = $cart->user_id();
        return new self( $cart, $user_id > 0 && get_userdata( $user_id ) ? new \WC_Customer( $user_id ) : null );
    }

    public function get_id(): int {
        return $this->account ? (int) $this->account->get_id() : 0;
    }

    // From the row: `WC_Customer` reads the billing email from user meta only, so a shopper who never checked out has none.
    public function get_billing_email(): string {
        return $this->cart->email();
    }

    public function get_billing_first_name(): string {
        return $this->cart->first_name();
    }

    public function get_billing_last_name(): string {
        return $this->account ? (string) $this->account->get_billing_last_name() : '';
    }

    public function get_billing_phone(): string {
        return $this->account ? (string) $this->account->get_billing_phone() : '';
    }

    public function get_billing_country(): string {
        return $this->account ? (string) $this->account->get_billing_country() : '';
    }
}
