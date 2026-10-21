<?php
namespace YayMatik\Workflow\Steps\Actions;

use YayMatik\Workflow\Data\Scope;
use YayMatik\Workflow\Data\Type;
use YayMatik\Workflow\Modules\WoocommerceModule;
use YayMatik\Workflow\Steps\Core\Action;
use YayMatik\Workflow\Steps\Core\Field;
use YayMatik\Workflow\Steps\Core\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Email to the store team, sent like WooCommerce's own "New order" email: from the WooCommerce
 * sender, wrapped in the WooCommerce email template.
 * Config: [ 'recipients' => comma-separated emails, blank = site admin email, 'subject' => string, 'message' => string ].
 * Subject and message accept merge tags. The subject is also the email heading.
 */
class SendInternalEmail extends Action {

    public const ID = 'send_internal_email';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Send internal email', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Email your store team, from your WooCommerce store email address.', 'yaymatik' );
    }

    public function plugin(): string {
        return WoocommerceModule::ID;
    }

    public function icon(): string {
        return 'mail';
    }

    public function category(): string {
        return self::CATEGORY_EMAIL;
    }

    public function requires(): array {
        return [];
    }

    /** The read-only From line is the WooCommerce sender, e.g. "Store <store@example.com>". */
    public function fields(): array {
        return [
            Field::notice( 'from', __( 'From', 'yaymatik' ), self::from_line() ),
            Field::emails( 'recipients', __( 'To', 'yaymatik' ) )
                ->placeholder( (string) get_option( 'admin_email' ) )
                ->help( __( 'Separate addresses with commas. Leave blank to use the site admin email.', 'yaymatik' ) ),
            Field::text( 'subject', __( 'Subject', 'yaymatik' ) )
                /* translators: %s: step name, e.g. "Send internal email" */
                ->required( __( '%s needs a subject.', 'yaymatik' ) )
                ->variables(),
            Field::textarea( 'message', __( 'Message', 'yaymatik' ) )
                /* translators: %s: step name, e.g. "Send internal email" */
                ->required( __( '%s needs a message.', 'yaymatik' ) )
                ->variables(),
        ];
    }

    public function summary(): string {
        /* translators: %recipients%: email addresses; keep the placeholder as it is. */
        return __( 'Send to %recipients%', 'yaymatik' );
    }

    public function outputs(): array {
        return [
            'to' => [
                'label' => __( 'Sent to', 'yaymatik' ),
                'type'  => Type::list_of( Type::string() ),
            ],
        ];
    }

    /**
     * Outputs the addresses it was sent to; never the subject or body.
     *
     * @param Scope $scope  Run scope.
     * @param array $config Sanitized, valid config.
     */
    public function run( Scope $scope, array $config ): StepResult {
        $recipients = Field::split_emails( $config['recipients'] );
        if ( ! $recipients ) {
            $recipients = [ (string) get_option( 'admin_email' ) ];
        }

        // Merged values may contain line breaks; a subject must stay one line.
        $subject = Field::single_line( self::render( $config['subject'], $scope ) );
        $mailer  = WC()->mailer();
        $body    = $mailer->wrap_message( $subject, self::render( $config['message'], $scope, true ) );

        if ( ! $mailer->send( $recipients, $subject, $body ) ) {
            return StepResult::failed( new \WP_Error( 'yaymatik_mail_failed', __( 'Couldn’t send the email.', 'yaymatik' ) ) );
        }
        return StepResult::ok( [ 'to' => $recipients ] );
    }

    /**
     * WooCommerce sender, for the From line of email steps the admin draws itself.
     * Shape must match `automation.email` in apps/admin-page/src/types/localize.d.ts.
     */
    public static function sender(): array {
        return [ 'fromLine' => self::from_line() ];
    }

    /** "Store <store@example.com>"; the address alone when the sender has no name. */
    private static function from_line(): string {
        // Stored escaped, like the site title; the notice is plain text.
        $name = wp_specialchars_decode( self::from_name(), ENT_QUOTES );
        return '' === $name ? self::from_address() : sprintf( '%s <%s>', $name, self::from_address() );
    }

    private static function from_name(): string {
        return (string) get_option( 'woocommerce_email_from_name', get_bloginfo( 'name' ) );
    }

    /** A blank address falls back to the site admin's, as WooCommerce's own default. */
    private static function from_address(): string {
        $address = (string) get_option( 'woocommerce_email_from_address' );
        return '' !== $address ? $address : (string) get_option( 'admin_email' );
    }
}
