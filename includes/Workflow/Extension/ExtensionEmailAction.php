<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Workflow\Actions\SendEmail;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Template;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Data\Values;
use YayMatik\Workflow\Core\Email\EmailDelivery;
use YayMatik\Workflow\Core\Email\EmailStep;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Another plugin's email action: its `build`, then core's merge tags, footer and sending (docs/extension-api.md §5.5).
 *
 * @internal
 */
final class ExtensionEmailAction extends ExtensionAction implements EmailStep {

    /** @var EmailDelivery */
    private $delivery;

    /**
     * @param string             $module
     * @param string             $id
     * @param array              $args     From `Declaration::action()`, with `email`.
     * @param EmailDelivery|null $delivery Null = with the stored unsubscribe secret and list.
     */
    public function __construct( string $module, string $id, array $args, ?EmailDelivery $delivery = null ) {
        parent::__construct( $module, $id, $args );
        $this->delivery = $delivery ?? new EmailDelivery();
    }

    public function outputs(): array {
        return [
            'to' => [
                'label' => __( 'Sent to', 'yaymatik-automation-for-woocommerce' ),
                'type'  => Type::string(),
            ],
        ];
    }

    /** `{{ customer.email }}` needs a customer: the picker and the Validator say so before a run fails. */
    public function requires(): array {
        $requires = parent::requires();
        return 'customer' === $this->args['email']['recipient'] ? array_values( array_unique( array_merge( $requires, [ 'customer' ] ) ) ) : $requires;
    }

    public function email_audience(): string {
        return $this->args['email']['audience'];
    }

    /**
     * @param Scope $scope
     * @param array $config
     */
    public function run( Scope $scope, array $config ): StepResult {
        return $this->delivery->deliver( $this, $config, $scope );
    }

    /**
     * `customer` = the run's customer email; else the config field the declaration names.
     *
     * @param array  $config
     * @param Values $values
     */
    public function email_recipient( array $config, Values $values ): string {
        $recipient = $this->args['email']['recipient'];
        $text      = 'customer' === $recipient ? '{{ customer.email }}' : (string) ( $config[ $recipient ] ?? '' );
        return UnsubscribeLink::normalize( Field::single_line( self::render( $text, $values ) ) );
    }

    /**
     * The plugin's `build`, then merge tags, then the footer when the HTML lacks the unsubscribe link.
     *
     * @param array       $config
     * @param Values      $values
     * @param string|null $unsubscribe Null = Preview or Send test: the plugin gets `#`; '' = a staff send.
     * @param bool        $test        Send test.
     * @throws \RuntimeException When `build` throws or returns no HTML: its message in Preview, "Couldn't build the email." in a run.
     */
    public function compose_email( array $config, Values $values, ?string $unsubscribe, bool $test = false ): array {
        $preview = null === $unsubscribe;
        $url     = self::AUDIENCE_STAFF === $this->email_audience() ? '' : ( $unsubscribe ?? '#' );
        $run     = new RunAdapter(
            $values,
            [
                'preview'     => $preview,
                'test'        => $test,
                'unsubscribe' => $url,
            ]
        );
        $built   = Guard::call( $this->module, 'build', fn() => ( $this->args['email']['build'] )( $config, $run ), fn( \Throwable $e ) => $e );
        if ( ! is_array( $built ) || ! is_string( $built['html'] ?? null ) ) {
            if ( ! $built instanceof \Throwable ) {
                Diagnostics::record( $this->module, 'build', 'build must return [ subject, html, wrap ].' );
            }
            $reason = $preview && $built instanceof \Throwable ? $built->getMessage() : __( 'Couldn’t build the email.', 'yaymatik-automation-for-woocommerce' );
            throw new \RuntimeException( esc_html( $reason ) );
        }

        $subject = SendEmail::plain_line( is_string( $built['subject'] ?? null ) ? $built['subject'] : '', $values );
        $html    = ( new Template() )->render_html( $built['html'], $values );
        $wrap    = ! empty( $built['wrap'] );
        // In Preview the link is `#`, which a design may use elsewhere: there, asking for the link counts as placing it.
        $placed = $preview ? $run->asked_for_unsubscribe() : self::has_link( $html, $url );
        if ( '' !== $url && ! $placed ) {
            $html = $wrap ? $html . SendEmail::footer( $url ) : self::before_body_end( $html, SendEmail::footer( $url ) );
        }
        return [
            'subject' => $subject,
            'heading' => $subject,
            'body'    => $wrap ? WC()->mailer()->wrap_message( $subject, $html ) : $html,
            'headers' => SendEmail::headers( $preview ? '' : $url ),
            'raw'     => ! $wrap,
        ];
    }

    /** Default "Sent to <address>". */
    protected function result(): string {
        /* translators: {{ output.to }}: email address; keep {{ … }} as it is. */
        return $this->args['result'] ?? __( 'Sent to {{ output.to }}', 'yaymatik-automation-for-woocommerce' );
    }

    /**
     * Whether the HTML links to the URL, as written or escaped for an attribute.
     *
     * @param string $html
     * @param string $url
     */
    private static function has_link( string $html, string $url ): bool {
        foreach ( array_unique( [ $url, esc_url( $url ), esc_attr( $url ) ] ) as $form ) {
            if ( false !== strpos( $html, 'href="' . $form . '"' ) || false !== strpos( $html, "href='" . $form . "'" ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $html
     * @param string $footer
     */
    private static function before_body_end( string $html, string $footer ): string {
        $end = strripos( $html, '</body>' );
        return false === $end ? $html . $footer : substr( $html, 0, $end ) . $footer . substr( $html, $end );
    }
}
