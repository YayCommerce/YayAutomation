<?php
namespace YayMatik\Workflow\Core\Email;

use YayMatik\Workflow\Core\Data\Values;
use YayMatik\Workflow\Core\Steps\StepResult;
use YayMatik\Workflow\Core\Storage\UnsubscribeStore;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one email step's email at run time: recipient, unsubscribed check, link, send, output `to`.
 */
final class EmailDelivery {

    /** @var UnsubscribeLink|null Null = from the stored secret, when first needed. */
    private $links;

    /** @var callable fn( string $email ): bool */
    private $unsubscribed;

    /**
     * @param UnsubscribeLink|null $links        Null = with the stored secret.
     * @param callable|null        $unsubscribed fn( string $email ): bool; null = `UnsubscribeStore::has()`.
     */
    public function __construct( ?UnsubscribeLink $links = null, ?callable $unsubscribed = null ) {
        $this->links        = $links;
        $this->unsubscribed = $unsubscribed ?? [ UnsubscribeStore::class, 'has' ];
    }

    /**
     * @param EmailStep $step
     * @param array     $config
     * @param Values    $values
     */
    public function deliver( EmailStep $step, array $config, Values $values ): StepResult {
        $to = $step->email_recipient( $config, $values );
        if ( '' === $to ) {
            return StepResult::failed( __( 'No valid email address.', 'yaymatik-automation-for-woocommerce' ) );
        }
        $customer = EmailStep::AUDIENCE_CUSTOMER === $step->email_audience();
        if ( $customer && ( $this->unsubscribed )( $to ) ) {
            return StepResult::skipped( __( 'The customer unsubscribed from these emails.', 'yaymatik-automation-for-woocommerce' ) );
        }

        $email = $step->compose_email( $config, $values, $customer ? $this->links()->url( $to ) : '' );
        if ( ! self::send( $to, $email ) ) {
            return StepResult::failed( __( 'Couldn’t send the email.', 'yaymatik-automation-for-woocommerce' ) );
        }
        return StepResult::ok( [ 'to' => $to ] );
    }

    /**
     * Sends through WooCommerce's mailer, so its sender name and address apply.
     *
     * @param string|string[] $to
     * @param array           $email `EmailStep::compose_email()` output.
     */
    public static function send( $to, array $email ): bool {
        if ( empty( $email['raw'] ) ) {
            return (bool) WC()->mailer()->send( $to, $email['subject'], $email['body'], $email['headers'] );
        }
        // A plugin's own HTML document: WooCommerce would inline its email styles into it.
        $as_built = fn() => fn( $content ) => $content;
        add_filter( 'woocommerce_mail_style_inline_callback', $as_built );
        try {
            return (bool) WC()->mailer()->send( $to, $email['subject'], $email['body'], $email['headers'] );
        } finally {
            remove_filter( 'woocommerce_mail_style_inline_callback', $as_built );
        }
    }

    private function links(): UnsubscribeLink {
        $this->links = $this->links ?? UnsubscribeLink::from_options();
        return $this->links;
    }
}
