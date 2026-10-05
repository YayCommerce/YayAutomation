<?php
namespace YayAutomation\Engine\Blocks\Actions;

use YayAutomation\Engine\Blocks\Core\Action;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text email about the order.
 * Config: [ 'recipients' => comma-separated emails, blank = site admin email, 'subject' => string, 'body' => string ].
 * Subject and body accept the merge tags listed in `merge_tags()`.
 */
class SendAdminEmail extends Action {

    public const ID = 'send_admin_email';

    public function id(): string {
        return self::ID;
    }

    public function sanitize( $config ): array {
        $config = is_array( $config ) ? $config : [];

        return [
            'recipients' => sanitize_text_field( (string) ( $config['recipients'] ?? '' ) ),
            'subject'    => self::single_line( (string) ( $config['subject'] ?? '' ) ),
            'body'       => trim( self::plain_text( (string) ( $config['body'] ?? '' ) ) ),
        ];
    }

    /**
     * Cleans plain-text email content. Not sanitize_text(area)_field: those treat `<{{customer_email}}>`
     * as a tag and strip it, and turn `<` into `&lt;`. The mail is sent as text/plain, so markup is inert.
     *
     * @param string $text Raw text.
     */
    private static function plain_text( string $text ): string {
        $text = str_replace( "\r\n", "\n", wp_check_invalid_utf8( $text ) );
        // Control characters, keeping tab and newline.
        return (string) preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/', '', $text );
    }

    /**
     * Subject line: CR/LF become spaces so it can't inject mail headers.
     *
     * @param string $text Raw or merged subject.
     */
    private static function single_line( string $text ): string {
        return trim( self::plain_text( (string) preg_replace( '/[\r\n\t]+/', ' ', $text ) ) );
    }

    public function validate( array $config ) {
        foreach ( self::split_recipients( $config['recipients'] ) as $email ) {
            if ( ! is_email( $email ) ) {
                /* translators: %s: the invalid email address */
                return $this->invalid( sprintf( __( 'Invalid recipient email: %s', 'yay-automation' ), $email ) );
            }
        }
        if ( '' === $config['subject'] ) {
            return $this->invalid( __( 'Enter a subject.', 'yay-automation' ) );
        }
        if ( '' === $config['body'] ) {
            return $this->invalid( __( 'Enter a message.', 'yay-automation' ) );
        }
        return true;
    }

    public function run( array $context, array $config ) {
        $recipients = self::split_recipients( $config['recipients'] );
        if ( ! $recipients ) {
            $recipients = [ get_option( 'admin_email' ) ];
        }

        $tags = self::merge_tags( $context['order'] );
        // Tag values come from order data, so clean the subject again after merging.
        $subject = self::single_line( strtr( $config['subject'], $tags ) );
        $body    = strtr( $config['body'], $tags );

        // Merged order data may contain raw < >. The header alone isn't enough: wp_mail() runs the
        // `wp_mail_content_type` filter after parsing headers, so a plugin could still switch it to HTML.
        $force_plain = function () {
            return 'text/plain';
        };
        add_filter( 'wp_mail_content_type', $force_plain, PHP_INT_MAX );
        try {
            $sent = wp_mail( $recipients, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
        } finally {
            remove_filter( 'wp_mail_content_type', $force_plain, PHP_INT_MAX );
        }
        if ( ! $sent ) {
            return new \WP_Error( 'yay_automation_mail_failed', __( 'wp_mail() could not send the email.', 'yay-automation' ) );
        }
        return true;
    }

    /**
     * `{{tag}}` => value. Keep in sync with the tag list in the TS block form.
     *
     * @param \WC_Order $order Order that triggered the workflow.
     */
    public static function merge_tags( \WC_Order $order ): array {
        $total = wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] );

        return [
            '{{order_number}}'    => $order->get_order_number(),
            '{{order_total}}'     => html_entity_decode( wp_strip_all_tags( $total ), ENT_QUOTES, 'UTF-8' ),
            '{{order_status}}'    => wc_get_order_status_name( $order->get_status() ),
            '{{customer_name}}'   => trim( $order->get_formatted_billing_full_name() ),
            '{{customer_email}}'  => $order->get_billing_email(),
            '{{order_admin_url}}' => $order->get_edit_order_url(),
            '{{site_name}}'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
        ];
    }

    private static function split_recipients( string $recipients ): array {
        return array_values( array_filter( array_map( 'trim', explode( ',', $recipients ) ) ) );
    }
}
