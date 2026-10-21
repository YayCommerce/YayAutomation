<?php
namespace YayMatik\Workflow\Steps\Actions;

use YayMatik\Workflow\Data\Scope;
use YayMatik\Workflow\Data\Template;
use YayMatik\Workflow\Data\Values;
use YayMatik\Workflow\Data\Type;
use YayMatik\Workflow\Email\EmailBody;
use YayMatik\Workflow\Email\UnsubscribeLink;
use YayMatik\Workflow\Modules\WoocommerceModule;
use YayMatik\Workflow\Steps\Core\Action;
use YayMatik\Workflow\Steps\Core\Field;
use YayMatik\Workflow\Steps\Core\StepResult;
use YayMatik\Workflow\Storage\UnsubscribeStore;

defined( 'ABSPATH' ) || exit;

/**
 * Email to a customer, sent like WooCommerce's own customer emails: from the WooCommerce sender,
 * in the WooCommerce email template with its styles inlined, with an unsubscribe link in the body's
 * footer and one-click unsubscribe headers.
 *
 * Config: [ 'to' => one address or variable, 'subject' => text, 'heading' => text (blank = the
 * subject), 'message' => HTML ]. All accept merge tags; subject and heading are plain text.
 *
 * The final To address (after variables are filled in) decides: not an email → the step fails;
 * unsubscribed → the step is skipped, and the run goes on.
 */
class SendEmail extends Action {

    public const ID = 'send_email';

    /** Run history reason when the address is unsubscribed. */
    public const SKIPPED_UNSUBSCRIBED = 'unsubscribed';

    /** @var UnsubscribeLink|null Null = from the stored secret, when first needed. */
    private $links;

    /** @var callable fn( string $email ): bool */
    private $unsubscribed;

    /**
     * @param UnsubscribeLink|null $links        Unsubscribe links; null = with the stored secret.
     * @param callable|null        $unsubscribed Unsubscribe lookup; null = `UnsubscribeStore::has()`.
     */
    public function __construct( ?UnsubscribeLink $links = null, ?callable $unsubscribed = null ) {
        $this->links        = $links;
        $this->unsubscribed = $unsubscribed ?? [ UnsubscribeStore::class, 'has' ];
    }

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Send email', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Send an email to a customer in your store’s WooCommerce email style, with an unsubscribe link.', 'yaymatik' );
    }

    public function plugin(): string {
        return WoocommerceModule::ID;
    }

    public function icon(): string {
        return 'send';
    }

    public function category(): string {
        return self::CATEGORY_EMAIL;
    }

    public function requires(): array {
        return [];
    }

    public function fields(): array {
        return [
            Field::notice( 'from', __( 'From', 'yaymatik' ), SendInternalEmail::sender()['fromLine'] ),
            Field::text( 'to', __( 'To', 'yaymatik' ) )
                /* translators: %s: step name, e.g. "Send email" */
                ->required( __( '%s needs an email address.', 'yaymatik' ) )
                ->variables()
                ->default( '{{ customer.email }}' )
                ->help( __( 'One email address, or a variable such as the customer’s email.', 'yaymatik' ) ),
            Field::text( 'subject', __( 'Subject', 'yaymatik' ) )
                /* translators: %s: step name, e.g. "Send email" */
                ->required( __( '%s needs a subject.', 'yaymatik' ) )
                ->variables(),
            Field::text( 'heading', __( 'Heading', 'yaymatik' ) )
                ->variables()
                ->help( __( 'Shown at the top of the email. Leave blank to use the subject.', 'yaymatik' ) ),
            Field::html( 'message', __( 'Message', 'yaymatik' ) )
                /* translators: %s: step name, e.g. "Send email" */
                ->required( __( '%s needs a message.', 'yaymatik' ) )
                ->variables(),
        ];
    }

    public function summary(): string {
        /* translators: %to%: email address or variable; keep the placeholder as it is. */
        return __( 'Send to %to%', 'yaymatik' );
    }

    public function outputs(): array {
        return [
            'to' => [
                'label' => __( 'Sent to', 'yaymatik' ),
                'type'  => Type::string(),
            ],
        ];
    }

    /**
     * @param array $config Output of `sanitize()`.
     * @return true|\WP_Error
     */
    public function validate( array $config ) {
        $error = $this->field_error( $config );
        if ( $error ) {
            return $error;
        }
        $to = $config['to'];
        // A variable is checked when the email is sent; a typed address now.
        $one = false === strpos( $to, ',' ) && ( false !== strpos( $to, '{{' ) || is_email( $to ) );
        return $one ? true : $this->invalid( __( 'Enter one email address or a variable.', 'yaymatik' ), 'to' );
    }

    /**
     * Outputs the address it was sent to; never the subject or body.
     *
     * @param Scope $scope  Run scope.
     * @param array $config Sanitized, valid config.
     */
    public function run( Scope $scope, array $config ): StepResult {
        $to = self::recipient( $config, $scope );
        if ( '' === $to ) {
            return StepResult::failed( new \WP_Error( 'yaymatik_no_email', __( 'No valid email address.', 'yaymatik' ) ) );
        }
        if ( ( $this->unsubscribed )( $to ) ) {
            return StepResult::skipped( self::SKIPPED_UNSUBSCRIBED );
        }

        $email = $this->compose( $config, $scope, $this->links()->url( $to ) );
        if ( ! WC()->mailer()->send( $to, $email['subject'], $email['body'], $email['headers'] ) ) {
            return StepResult::failed( new \WP_Error( 'yaymatik_mail_failed', __( 'Couldn’t send the email.', 'yaymatik' ) ) );
        }
        return StepResult::ok( [ 'to' => $to ] );
    }

    /**
     * The email as it goes out: subject, wrapped body with the unsubscribe link, headers.
     *
     * @param array       $config      Sanitized config.
     * @param Values      $values      Run scope, or sample values in a preview.
     * @param string|null $unsubscribe The address's unsubscribe link; null = a link that goes nowhere
     *                                 (`#`, same look) and no List-Unsubscribe headers, for previews
     *                                 and test emails, which must never carry a real token.
     * @return array [ 'subject' => string, 'heading' => string, 'body' => HTML, 'headers' => string ].
     */
    public function compose( array $config, Values $values, ?string $unsubscribe ): array {
        $subject = self::plain_line( $config['subject'], $values );
        $heading = self::plain_line( $config['heading'], $values );
        $heading = '' === $heading ? $subject : $heading;

        $body = EmailBody::render( $config['message'], $values ) . self::footer( $unsubscribe ?? '#' );
        return [
            'subject' => $subject,
            'heading' => $heading,
            // WooCommerce's template; the mailer inlines its styles when it sends.
            'body'    => WC()->mailer()->wrap_message( $heading, $body ),
            'headers' => self::headers( (string) $unsubscribe ),
        ];
    }

    /**
     * The To address a config gives, normalized; empty when it isn't one email.
     *
     * @param array  $config Sanitized config.
     * @param Values $values Where the data comes from.
     */
    public static function recipient( array $config, Values $values ): string {
        return UnsubscribeLink::normalize( Field::single_line( self::render( $config['to'], $values ) ) );
    }

    /**
     * Headers for one-click unsubscribe (RFC 8058), after WooCommerce's HTML content type.
     *
     * @param string $url Unsubscribe link.
     */
    public static function headers( string $url ): string {
        $headers = "Content-Type: text/html\r\n";
        if ( '' !== $url ) {
            $headers .= 'List-Unsubscribe: <' . esc_url_raw( $url ) . ">\r\n";
            $headers .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
        }
        return $headers;
    }

    /**
     * The unsubscribe link under the message, inside the WooCommerce wrapper.
     *
     * @param string $url Unsubscribe link.
     */
    private static function footer( string $url ): string {
        if ( '' === $url ) {
            return '';
        }
        return '<p style="margin:32px 0 0;font-size:12px;text-align:center;">'
            . '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Unsubscribe', 'yaymatik' ) . '</a></p>';
    }

    /**
     * A subject or heading as plain text on one line: HTML tags typed in the text removed, then merge
     * tags filled in. Only real tags (`<b>`, `</p>`, `<br/>`) go; a lone `<` stays, as in "Save <30%
     * today". Values are data and are never stripped.
     *
     * @param string $text  Stored text.
     * @param Values $scope Where the data comes from.
     */
    public static function plain_line( string $text, Values $scope ): string {
        $template = new Template();
        $stripped = '';
        foreach ( $template->parse( $text ) as $token ) {
            $stripped .= 'text' === $token['type']
                ? html_entity_decode( (string) preg_replace( '#</?[a-z][a-z0-9-]*(?:\s[^<>]*)?/?>#i', '', $token['text'] ), ENT_QUOTES, 'UTF-8' )
                : $token['raw'];
        }
        return Field::single_line( $template->render( $stripped, $scope ) );
    }

    private function links(): UnsubscribeLink {
        $this->links = $this->links ?? UnsubscribeLink::from_options();
        return $this->links;
    }
}
