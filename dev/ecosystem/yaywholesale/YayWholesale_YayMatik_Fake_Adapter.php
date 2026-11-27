<?php
/**
 * YayWholesale's fake adapter: a role kept in user meta, a fixed minimum, no YayWholesale code.
 *
 * @package YayWholesale
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- another plugin: its own text domain.

/** Stands in for YayWholesale until its real adapter is written. */
final class YayWholesale_YayMatik_Fake_Adapter {

    private const ROLE_META = '_yaywholesale_fake_role';

    private const MINIMUM = 500.0;

    /** Role => label. */
    public function role_options(): array {
        return [
            'wholesale_silver' => 'Wholesale Silver',
            'wholesale_gold'   => 'Wholesale Gold',
        ];
    }

    /** Status => label. */
    public function status_options(): array {
        return [
            'none'     => 'Not a wholesaler',
            'pending'  => 'Request pending',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
        ];
    }

    /**
     * @param \YayMatik\Api\CustomerView $customer
     */
    public function customer_role( $customer ): string {
        return $customer->user_id() ? (string) get_user_meta( $customer->user_id(), self::ROLE_META, true ) : '';
    }

    /**
     * @param \YayMatik\Api\CustomerView $customer
     */
    public function customer_status( $customer ): string {
        return '' !== $this->customer_role( $customer ) ? 'approved' : 'none';
    }

    /**
     * @param \YayMatik\Api\CartView $cart
     */
    public function cart_minimum( $cart ): float {
        return self::MINIMUM;
    }

    /**
     * @param \YayMatik\Api\CartView $cart
     */
    public function cart_missing_amount( $cart ): float {
        return max( 0.0, self::MINIMUM - $cart->total() );
    }

    /**
     * @param array              $config
     * @param \YayMatik\Api\Step $step
     */
    public function set_role( array $config, $step ) {
        $user = $step->user();
        if ( ! $user ) {
            return $step->skip( __( 'Guests can’t have a wholesale role.', 'yaywholesale' ) );
        }
        if ( $step->is_test() ) {
            return $step->skip( __( 'A test changes no role.', 'yaywholesale' ) );
        }
        update_user_meta( $user->ID, self::ROLE_META, $config['role'] );
        return $step->done( [ 'role' => $config['role'] ] );
    }
}
