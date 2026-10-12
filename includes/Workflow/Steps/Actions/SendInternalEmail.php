<?php
namespace YayAutomation\Workflow\Steps\Actions;

use YayAutomation\Workflow\Data\Scope;
use YayAutomation\Workflow\Data\Type;
use YayAutomation\Workflow\Definition\Messages;
use YayAutomation\Workflow\Extend\Modules;
use YayAutomation\Workflow\Extend\Plugins;
use YayAutomation\Workflow\Steps\Core\Action;
use YayAutomation\Workflow\Steps\Core\Field;
use YayAutomation\Workflow\Steps\Core\StepResult;

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
        return __( 'Send internal email', 'yay-automation' );
    }

    public function description(): string {
        return __( "Send an email notification to your staff. The email will be sent from your store's contact email address.", 'yay-automation' );
    }

    public function plugin(): string {
        return Plugins::WOOCOMMERCE;
    }

    public function module(): string {
        return Modules::CORE;
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

    /** The sender is shown from `sender()` (EmailMeta); the notice explains where it comes from. */
    public function fields(): array {
        return [
            Field::notice( 'from', __( 'From', 'yay-automation' ), __( 'The store email used to contact customers', 'yay-automation' ) ),
            Field::emails( 'recipients', __( 'To', 'yay-automation' ) )
                ->help( __( 'Separate addresses with commas. Leave blank to use the site admin email.', 'yay-automation' ) ),
            Field::text( 'subject', __( 'Subject', 'yay-automation' ) )
                /* translators: %s: step name, e.g. "Send internal email" */
                ->required( __( '%s needs a subject.', 'yay-automation' ) )
                ->variables(),
            Field::textarea( 'message', __( 'Message', 'yay-automation' ) )
                /* translators: %s: step name, e.g. "Send internal email" */
                ->required( __( '%s needs a message.', 'yay-automation' ) )
                ->variables(),
        ];
    }

    public function summary(): string {
        /* translators: %recipients%: email addresses, %subject%: email subject; keep the placeholders as they are. */
        return __( 'Send email to %recipients% with the following subject: %subject%', 'yay-automation' );
    }

    public function outputs(): array {
        return [
            'to' => [
                'label' => __( 'Sent to', 'yay-automation' ),
                'type'  => Type::list_of( Type::string() ),
            ],
        ];
    }

    /**
     * Field rules, in today's order and words: recipients first, then subject and message.
     *
     * @param array $config Sanitized config.
     */
    public function validate( array $config ) {
        foreach ( Field::split_emails( $config['recipients'] ) as $email ) {
            if ( ! is_email( $email ) ) {
                return $this->invalid( Messages::get( 'invalidEmail', $email ), 'recipients' );
            }
        }
        return $this->field_error( $config ) ?? true;
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
            return StepResult::failed( new \WP_Error( 'yay_automation_mail_failed', __( 'Couldn’t send the email.', 'yay-automation' ) ) );
        }
        return StepResult::ok( [ 'to' => $recipients ] );
    }

    /**
     * WooCommerce sender, for the read-only "From" field.
     * Shape must match `EmailMeta` in apps/admin-page/src/types/localize.d.ts.
     */
    public static function sender(): array {
        return [
            'fromName'    => (string) get_option( 'woocommerce_email_from_name', get_bloginfo( 'name' ) ),
            'fromAddress' => (string) get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ),
            'settingsUrl' => admin_url( 'admin.php?page=wc-settings&tab=email' ),
        ];
    }
}
