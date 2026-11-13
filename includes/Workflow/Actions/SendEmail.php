<?php
namespace YayMatik\Workflow\Actions;

use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Template;
use YayMatik\Workflow\Core\Data\Values;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Email\EmailBody;
use YayMatik\Workflow\Core\Email\EmailDelivery;
use YayMatik\Workflow\Core\Email\EmailStep;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;
use YayMatik\Workflow\Core\Steps\Action;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\StepResult;

defined( 'ABSPATH' ) || exit;

/** Email to a customer in the WooCommerce email template, with an unsubscribe link. */
class SendEmail extends Action implements EmailStep {

    public const ID = 'send_email';

    /** @var EmailDelivery */
    private $delivery;

    /**
     * @param UnsubscribeLink|null $links        Null = with the stored secret.
     * @param callable|null        $unsubscribed fn( string $email ): bool; null = `UnsubscribeStore::has()`.
     */
    public function __construct( ?UnsubscribeLink $links = null, ?callable $unsubscribed = null ) {
        $this->delivery = new EmailDelivery( $links, $unsubscribed );
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
                /* translators: %s: step name */
                ->required( __( '%s needs an email address.', 'yaymatik' ) )
                ->variables()
                ->default( '{{ customer.email }}' )
                ->help( __( 'One email address, or a variable such as the customer’s email.', 'yaymatik' ) ),
            Field::text( 'subject', __( 'Subject', 'yaymatik' ) )
                /* translators: %s: step name */
                ->required( __( '%s needs a subject.', 'yaymatik' ) )
                ->variables(),
            Field::text( 'heading', __( 'Heading', 'yaymatik' ) )
                ->variables()
                ->help( __( 'Shown at the top of the email. Leave blank to use the subject.', 'yaymatik' ) ),
            Field::html( 'message', __( 'Message', 'yaymatik' ) )
                /* translators: %s: step name */
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
     * @param array $config
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
     * @param Scope $scope
     * @param array $config
     */
    public function run( Scope $scope, array $config ): StepResult {
        return $this->delivery->deliver( $this, $config, $scope );
    }

    public function email_audience(): string {
        return self::AUDIENCE_CUSTOMER;
    }

    /**
     * The email as it goes out: subject, wrapped body with the unsubscribe link, headers.
     *
     * @param array       $config
     * @param Values      $values
     * @param string|null $unsubscribe Null = a link to `#` and no List-Unsubscribe headers.
     * @param bool        $test        Not used: Send test gets the same email.
     * @return array [ 'subject' => string, 'heading' => string, 'body' => HTML, 'headers' => string, 'raw' => false ]
     */
    public function compose_email( array $config, Values $values, ?string $unsubscribe, bool $test = false ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- EmailStep's signature.
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
            'raw'     => false,
        ];
    }

    /**
     * The To address a config gives, normalized; empty when it isn't one email.
     *
     * @param array  $config
     * @param Values $values
     */
    public function email_recipient( array $config, Values $values ): string {
        return UnsubscribeLink::normalize( Field::single_line( self::render( $config['to'], $values ) ) );
    }

    /**
     * Headers for one-click unsubscribe (RFC 8058), after WooCommerce's HTML content type.
     *
     * @param string $url
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
     * The unsubscribe link under the message.
     *
     * @param string $url
     */
    public static function footer( string $url ): string {
        if ( '' === $url ) {
            return '';
        }
        return '<p style="margin:32px 0 0;font-size:12px;text-align:center;">'
            . '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Unsubscribe', 'yaymatik' ) . '</a></p>';
    }

    /**
     * A subject or heading as plain text on one line: typed HTML tags removed, then merge tags filled in.
     *
     * @param string $text
     * @param Values $scope
     */
    public static function plain_line( string $text, Values $scope ): string {
        $template = new Template();
        $stripped = '';
        // Only real tags go; a lone `<` stays ("Save <30% today"). Values are data and are never stripped.
        foreach ( $template->parse( $text ) as $token ) {
            $stripped .= 'text' === $token['type']
                ? html_entity_decode( (string) preg_replace( '#</?[a-z][a-z0-9-]*(?:\s[^<>]*)?/?>#i', '', $token['text'] ), ENT_QUOTES, 'UTF-8' )
                : $token['raw'];
        }
        return Field::single_line( $template->render( $stripped, $scope ) );
    }
}
