<?php
namespace YayAutomation\Engine\Blocks\Actions;

use YayAutomation\Engine\Blocks\Core\Action;
use YayAutomation\Engine\Data\Variables;
use YayAutomation\Engine\Plugins;

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

    public function plugin(): string {
        return Plugins::WOOCOMMERCE;
    }

    public function category(): string {
        return self::CATEGORY_EMAIL;
    }

    public function requires(): array {
        return [];
    }

    public function sanitize( $config ): array {
        $config = is_array( $config ) ? $config : [];

        return [
            'recipients' => sanitize_text_field( (string) ( $config['recipients'] ?? '' ) ),
            'subject'    => self::single_line( (string) ( $config['subject'] ?? '' ) ),
            'message'    => self::plain_text( (string) ( $config['message'] ?? '' ) ),
        ];
    }

    public function validate( array $config ) {
        foreach ( self::split_recipients( $config['recipients'] ) as $email ) {
            if ( ! is_email( $email ) ) {
                /* translators: %s: the invalid email address */
                return $this->invalid( sprintf( __( 'Invalid recipient email: %s', 'yay-automation' ), $email ) );
            }
        }
        if ( '' === $config['subject'] ) {
            return $this->required();
        }
        if ( '' === $config['message'] ) {
            return $this->required();
        }
        return $this->unknown_variable( $config['subject'], $config['message'] ) ?? true;
    }

    public function run( array $context, array $config ) {
        $recipients = self::split_recipients( $config['recipients'] );
        if ( ! $recipients ) {
            $recipients = [ get_option( 'admin_email' ) ];
        }

        // Merged values may contain line breaks; a subject must stay one line.
        $subject = self::single_line( Variables::render( $config['subject'], $context ) );
        $mailer  = WC()->mailer();
        $body    = $mailer->wrap_message( $subject, Variables::render( $config['message'], $context, true ) );

        if ( ! $mailer->send( $recipients, $subject, $body ) ) {
            return new \WP_Error( 'yay_automation_mail_failed', __( 'The email could not be sent.', 'yay-automation' ) );
        }
        return true;
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

    private static function split_recipients( string $recipients ): array {
        return array_values( array_filter( array_map( 'trim', explode( ',', $recipients ) ) ) );
    }
}
