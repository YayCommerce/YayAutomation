<?php
namespace YayMatik\Workflow\Actions;

use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Steps\Action;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\StepResult;
use YayMatik\Workflow\DataItems\OrderData;

defined( 'ABSPATH' ) || exit;

/** Sends one of WooCommerce's customer order emails again, through WooCommerce. */
class ResendWcEmail extends Action {

    public const ID = 'resend_wc_email';

    /** Ids a plugin can add or remove: `apply_filters( 'yaymatik_resendable_emails', string[] $ids )`. Each must take `trigger( $order_id )`. */
    public const FILTER = 'yaymatik_resendable_emails';

    /** Customer emails whose `trigger()` takes only the order id. Refunded is left out: a resend would always read as a full refund. */
    public const CORE_EMAILS = [
        'customer_processing_order',
        'customer_completed_order',
        'customer_on_hold_order',
        'customer_invoice',
        'customer_cancelled_order',
        'customer_failed_order',
        'customer_review_request',
    ];

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Resend WooCommerce email', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Send one of WooCommerce’s order emails to the customer again.', 'yaymatik' );
    }

    public function icon(): string {
        return 'refresh-cw';
    }

    public function category(): string {
        return self::CATEGORY_EMAIL;
    }

    public function requires(): array {
        return [ OrderData::ID ];
    }

    public function fields(): array {
        return [
            Field::select( 'email', __( 'Email', 'yaymatik' ), [ self::class, 'emails' ] )
                /* translators: {{ step }}: step name, e.g. "Resend WooCommerce email"; keep {{ … }} as it is. */
                ->required( __( '{{ step }} needs an email.', 'yaymatik' ) ),
        ];
    }

    public function summary(): string {
        /* translators: {{ config.email }}: a WooCommerce email's name, e.g. "Completed order"; keep {{ … }} as it is. */
        return __( 'Resend {{ config.email }}', 'yaymatik' );
    }

    public function outputs(): array {
        return [
            'email' => [
                'label' => __( 'Email', 'yaymatik' ),
                'type'  => Type::string(),
            ],
        ];
    }

    /** Email id => title: the allowed ids this WooCommerce has, customer emails only. */
    public static function emails(): array {
        return array_map( fn( $email ) => $email->get_title(), self::resendable() );
    }

    public function run( Scope $scope, array $config ): StepResult {
        $order = $scope->entity( OrderData::ID );
        if ( ! $order ) {
            return StepResult::skipped( __( 'There is no order to send it for.', 'yaymatik' ) );
        }
        $id    = (string) $config['email'];
        $email = self::resendable()[ $id ] ?? null;
        if ( ! $email ) {
            /* translators: %s: email id */
            return StepResult::failed( sprintf( __( 'The WooCommerce email %s can’t be resent here any more.', 'yaymatik' ), $id ) );
        }
        // trigger() reports nothing, and sends nothing when the email is off or has no recipient.
        if ( ! $email->is_enabled() ) {
            /* translators: %s: email name, e.g. "Completed order" */
            return StepResult::skipped( sprintf( __( '%s is turned off in WooCommerce.', 'yaymatik' ), $email->get_title() ) );
        }
        if ( '' === (string) $order->get_billing_email() ) {
            return StepResult::skipped( __( 'This order has no billing email.', 'yaymatik' ) );
        }
        $email->trigger( $order->get_id() );
        return StepResult::ok( [ 'email' => $id ] );
    }

    /**
     * Email id => email object, for the allowed ids.
     *
     * @return \WC_Email[]
     */
    private static function resendable(): array {
        if ( ! function_exists( 'WC' ) ) {
            return [];
        }
        $allowed = (array) apply_filters( self::FILTER, self::CORE_EMAILS );
        $emails  = [];
        // get_emails() is keyed by class name.
        foreach ( WC()->mailer()->get_emails() as $email ) {
            if ( in_array( $email->id, $allowed, true ) && $email->is_customer_email() ) {
                $emails[ $email->id ] = $email;
            }
        }
        return $emails;
    }
}
