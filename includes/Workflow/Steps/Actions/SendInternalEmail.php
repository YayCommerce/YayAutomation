<?php
namespace YayMatik\Workflow\Steps\Actions;

use YayMatik\Workflow\Data\Scope;
use YayMatik\Workflow\Data\Type;
use YayMatik\Workflow\Data\Values;
use YayMatik\Workflow\Email\EmailBody;
use YayMatik\Workflow\Extend\Modules;
use YayMatik\Workflow\Extend\Plugins;
use YayMatik\Workflow\Steps\Core\Action;
use YayMatik\Workflow\Steps\Core\Field;
use YayMatik\Workflow\Steps\Core\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Email to the store team, sent like WooCommerce's own "New order" email: from the WooCommerce
 * sender, wrapped in the WooCommerce email template.
 * Config: [ 'recipients' => comma-separated emails, blank = site admin email, 'subject' => string, 'message' => HTML ].
 * Subject and message accept merge tags. The subject is also the email heading. A new step starts
 * with a sample subject and message. Unsubscribes don't apply: it goes to the store team.
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
                ->variables()
                ->default( __( 'Order {{ order.number }} is {{ order.status }}', 'yaymatik' ) ),
            Field::html( 'message', __( 'Message', 'yaymatik' ) )
                /* translators: %s: step name, e.g. "Send internal email" */
                ->required( __( '%s needs a message.', 'yaymatik' ) )
                ->variables()
                ->default( self::sample_message() ),
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

        $email = $this->compose( $config, $scope );
        if ( ! WC()->mailer()->send( $recipients, $email['subject'], $email['body'] ) ) {
            return StepResult::failed( new \WP_Error( 'yaymatik_mail_failed', __( 'Couldn’t send the email.', 'yaymatik' ) ) );
        }
        return StepResult::ok( [ 'to' => $recipients ] );
    }

    /**
     * The email as it goes out. The subject is also the heading.
     *
     * @param array  $config Sanitized config.
     * @param Values $values Run scope, or sample values in a preview.
     * @return array [ 'subject' => string, 'heading' => string, 'body' => HTML in the WooCommerce wrapper ].
     */
    public function compose( array $config, Values $values ): array {
        // Merged values may contain line breaks; a subject must stay one line.
        $subject = Field::single_line( self::render( $config['subject'], $values ) );
        return [
            'subject' => $subject,
            'heading' => $subject,
            'body'    => WC()->mailer()->wrap_message( $subject, EmailBody::render( $config['message'], $values ) ),
        ];
    }

    /** A new step's message: who ordered, the total and the items. */
    private static function sample_message(): string {
        return '<p>' . __( 'Order {{ order.number }} from {{ customer.full_name }} is {{ order.status }}.', 'yaymatik' ) . '</p>'
            . '<p>' . __( 'Total: {{ order.total }}', 'yaymatik' ) . '</p>'
            . '<p>{{ order.items }}</p>';
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
