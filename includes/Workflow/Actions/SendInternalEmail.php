<?php
namespace YayMatik\Workflow\Actions;

use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Data\Values;
use YayMatik\Workflow\Core\Email\EmailBody;
use YayMatik\Workflow\Core\Email\EmailDelivery;
use YayMatik\Workflow\Core\Email\EmailStep;
use YayMatik\Workflow\Core\Steps\Action;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\StepResult;

defined( 'ABSPATH' ) || exit;

/** Email to the store team in the WooCommerce email template; unsubscribes don't apply. */
class SendInternalEmail extends Action implements EmailStep {

    public const ID = 'send_internal_email';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Send internal email', 'yaymatik-automation-for-woocommerce' );
    }

    public function description(): string {
        return __( 'Email your store team, from your WooCommerce store email address.', 'yaymatik-automation-for-woocommerce' );
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

    public function fields(): array {
        return [
            Field::notice( 'from', __( 'From', 'yaymatik-automation-for-woocommerce' ), self::from_line() ),
            Field::emails( 'recipients', __( 'To', 'yaymatik-automation-for-woocommerce' ) )
                ->placeholder( (string) get_option( 'admin_email' ) )
                ->help( __( 'Separate addresses with commas. Leave blank to use the site admin email.', 'yaymatik-automation-for-woocommerce' ) ),
            Field::text( 'subject', __( 'Subject', 'yaymatik-automation-for-woocommerce' ) )
                /* translators: {{ step }}: step name; keep {{ … }} as it is. */
                ->required( __( '{{ step }} needs a subject.', 'yaymatik-automation-for-woocommerce' ) )
                ->variables()
                ->default( __( 'Order {{ order.number }} is {{ order.status }}', 'yaymatik-automation-for-woocommerce' ) ),
            Field::html( 'message', __( 'Message', 'yaymatik-automation-for-woocommerce' ) )
                /* translators: {{ step }}: step name; keep {{ … }} as it is. */
                ->required( __( '{{ step }} needs a message.', 'yaymatik-automation-for-woocommerce' ) )
                ->variables()
                ->default( self::sample_message() ),
        ];
    }

    public function summary(): string {
        /* translators: {{ config.recipients }}: email addresses; keep {{ … }} as it is. */
        return __( 'Send to {{ config.recipients }}', 'yaymatik-automation-for-woocommerce' );
    }

    public function outputs(): array {
        return [
            'to' => [
                'label' => __( 'Sent to', 'yaymatik-automation-for-woocommerce' ),
                'type'  => Type::list_of( Type::string() ),
            ],
        ];
    }

    /**
     * Outputs the addresses it was sent to; never the subject or body.
     *
     * @param Scope $scope
     * @param array $config
     */
    public function run( Scope $scope, array $config ): StepResult {
        $recipients = Field::split_emails( $config['recipients'] );
        if ( ! $recipients ) {
            $recipients = [ (string) get_option( 'admin_email' ) ];
        }

        if ( ! EmailDelivery::send( $recipients, $this->compose_email( $config, $scope, null ) ) ) {
            return StepResult::failed( __( 'Couldn’t send the email.', 'yaymatik-automation-for-woocommerce' ) );
        }
        return StepResult::ok( [ 'to' => $recipients ] );
    }

    public function email_audience(): string {
        return self::AUDIENCE_STAFF;
    }

    /**
     * The email as it goes out. The subject is also the heading.
     *
     * @param array       $config
     * @param Values      $values
     * @param string|null $unsubscribe Not used: staff emails have no unsubscribe link.
     * @param bool        $test        Not used: Send test gets the same email.
     * @return array [ 'subject' => string, 'heading' => string, 'body' => HTML in the WooCommerce wrapper, 'headers', 'raw' => false ]
     */
    public function compose_email( array $config, Values $values, ?string $unsubscribe, bool $test = false ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- EmailStep's signature.
        // Merged values may contain line breaks; a subject must stay one line.
        $subject = Field::single_line( self::render( $config['subject'], $values ) );
        return [
            'subject' => $subject,
            'heading' => $subject,
            'body'    => WC()->mailer()->wrap_message( $subject, EmailBody::render( $config['message'], $values ) ),
            'headers' => "Content-Type: text/html\r\n",
            'raw'     => false,
        ];
    }

    /**
     * As typed: blank reads as the placeholder (the site admin email).
     *
     * @param array  $config
     * @param Values $values
     */
    public function email_recipient( array $config, Values $values ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- EmailStep's signature.
        return (string) $config['recipients'];
    }

    /** A new step's message: who ordered, the total and the items. */
    private static function sample_message(): string {
        return '<p>' . __( 'Order {{ order.number }} from {{ customer.full_name }} is {{ order.status }}.', 'yaymatik-automation-for-woocommerce' ) . '</p>'
            . '<p>' . __( 'Total: {{ order.total }}', 'yaymatik-automation-for-woocommerce' ) . '</p>'
            . '<p>{{ order.items }}</p>';
    }

    /** WooCommerce sender; shape must match `automation.email` in apps/admin-page/src/types/localize.d.ts. */
    public static function sender(): array {
        return [
            'fromLine'    => self::from_line(),
            'fromName'    => self::from_name(),
            'fromAddress' => self::from_address(),
        ];
    }

    /** "Store <store@example.com>"; the address alone when the sender has no name. */
    private static function from_line(): string {
        $name = self::from_name();
        return '' === $name ? self::from_address() : sprintf( '%s <%s>', $name, self::from_address() );
    }

    /** Plain text: stored escaped, like the site title. */
    private static function from_name(): string {
        return wp_specialchars_decode( (string) get_option( 'woocommerce_email_from_name', get_bloginfo( 'name' ) ), ENT_QUOTES );
    }

    /** A blank address falls back to the site admin's, as WooCommerce's own default. */
    private static function from_address(): string {
        $address = (string) get_option( 'woocommerce_email_from_address' );
        return '' !== $address ? $address : (string) get_option( 'admin_email' );
    }
}
