<?php
/**
 * YayWholesale's integration class, a reference consumer of the Extension API, as its developer
 * would write it.
 *
 * @package YayMatik
 */

// phpcs:ignoreFile -- a third-party plugin's file, kept as written.

defined( 'ABSPATH' ) || exit;

final class YayWholesale_Reference_YayMatik {

    public static function init() {
        add_action( 'yaymatik_register', [ new self(), 'register' ] );
    }

    /** Runs only when YayMatik is active. */
    public function register( $registrar ) {
        $module = $registrar->module(
            [
                'id'           => 'yaywholesale',
                'label'        => 'YayWholesale',
                'logo'         => plugins_url( 'assets/yaymatik-logo.svg', YAYWHOLESALE_FILE ),
                'requires_api' => '1.0',
            ]
        );

        // Condition + merge tag: "Customer › Wholesale role is Gold", {{ customer.yaywholesale_role }}.
        $module->data_fields(
            'customer',
            [
                'yaywholesale_role' => [
                    'label'   => __( 'Wholesale role', 'yaywholesale' ),
                    'type'    => 'enum',
                    'options' => [ $this, 'role_options' ],
                    'value'   => [ $this, 'customer_role' ],
                ],
            ]
        );

        // Trigger: just a description. It starts when YayWholesale calls do_action( 'yaymatik_fire', … ).
        $module->trigger(
            'yaywholesale_approved',
            [
                'label'       => __( 'Wholesale request approved', 'yaywholesale' ),
                'description' => __( 'Starts when you approve a customer as a wholesaler.', 'yaywholesale' ),
                'category'    => 'customer',
                'provides'    => [ 'customer' ],
                'facts'       => [
                    'role' => [
                        'label'   => __( 'Approved role', 'yaywholesale' ),
                        'type'    => 'enum',
                        'options' => [ $this, 'role_options' ],
                    ],
                ],
                'once_per'    => 'subject', // one run per customer per workflow
            ]
        );

        // Action with a form YayMatik draws from 'settings'.
        $module->action(
            'yaywholesale_set_role',
            [
                'label'       => __( 'Set wholesale role', 'yaywholesale' ),
                'description' => __( 'Moves the customer to another wholesale role.', 'yaywholesale' ),
                'category'    => 'customer',
                'needs'    => [ 'customer' ],
                'settings'      => [
                    'role' => [
                        'type'     => 'select',
                        'label'    => __( 'Role', 'yaywholesale' ),
                        'options'  => [ $this, 'role_options' ],
                        'required' => true,
                    ],
                ],
                'summary'     => __( 'Set role to {{ config.role }}', 'yaywholesale' ),
                'result'      => __( 'Role is now {{ output.role }}', 'yaywholesale' ),
                'outputs'     => [
                    'role' => [ 'label' => __( 'New role', 'yaywholesale' ), 'type' => 'enum' ],
                ],
                'handle'         => [ $this, 'handle_set_role' ],
            ]
        );
    }

    /** The action: what happens when a workflow reaches "Set wholesale role". */
    public function handle_set_role( array $config, $step ) {
        $user = $step->user();
        if ( ! $user ) {
            return $step->skip( __( 'Guests can’t have a wholesale role.', 'yaywholesale' ) );
        }

        update_user_meta( $user->ID, '_yaywholesale_role', $config['role'] );

        return $step->done( [ 'role' => $config['role'] ] );
    }

    /** Value of the "Wholesale role" field. $customer is the same object whatever the trigger. */
    public function customer_role( $customer ): string {
        if ( ! $customer->user_id() ) {
            return '';
        }
        return (string) get_user_meta( $customer->user_id(), '_yaywholesale_role', true );
    }

    public function role_options(): array {
        return [
            'silver' => 'Silver',
            'gold'   => 'Gold',
        ];
    }
}
