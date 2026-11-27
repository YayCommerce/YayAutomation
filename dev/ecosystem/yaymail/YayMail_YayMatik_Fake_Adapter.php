<?php
/**
 * YayMail's fake adapter: sample designs, no YayMail code.
 *
 * @package YayMail
 */

defined( 'ABSPATH' ) || exit;

/** Stands in for YayMail until its real adapter is written. */
final class YayMail_YayMatik_Fake_Adapter {

    /** @var bool WooCommerce is sending the email `wp_mail()` gets next. */
    private $woocommerce = false;

    /** YayMail's own behavior: it wraps WooCommerce's emails in its design, but not one YayMatik marked. */
    public function hook_mail(): void {
        add_filter( 'woocommerce_mail_content', [ $this, 'note_woocommerce_email' ] );
        add_filter( 'wp_mail', [ $this, 'wrap' ] );
    }

    /**
     * @param string $content
     */
    public function note_woocommerce_email( $content ) {
        $this->woocommerce = true;
        return $content;
    }

    /**
     * @param array $atts
     */
    public function wrap( $atts ) {
        $woocommerce       = $this->woocommerce;
        $this->woocommerce = false;
        if ( $woocommerce && '' === self::yaymatik_mark( $atts['headers'] ?? '' ) ) {
            $atts['message'] = '<html><body data-wrapper="yaymail">' . $atts['message'] . '</body></html>';
        }
        return $atts;
    }

    /** Design id => name. */
    public function design_options(): array {
        return [
            '1' => 'Thank you',
            '2' => 'Review request',
            '3' => 'Cart reminder',
        ];
    }

    /**
     * Subject and HTML; YayMatik fills the merge tags and wraps it.
     *
     * @param array              $config
     * @param \YayMatik\Api\Step $step
     */
    public function build_email( array $config, $step ): array {
        $design = $this->design_options()[ (string) ( $config['design_id'] ?? '' ) ] ?? 'Thank you';
        $order  = $step->order();
        return [
            'subject' => (string) $config['subject'],
            'html'    => '<p>Hi {{ customer.first_name | default: "there" }},</p>'
                . '<p>' . esc_html( 'YayMail design: ' . $design ) . '</p>'
                . ( $order ? '<p>' . esc_html( 'Order #' . $order->get_order_number() ) . '</p>' : '' ),
            'wrap'    => true,
        ];
    }

    /**
     * The `X-YayMatik-Mark` header in `wp_mail()` headers: a string, lines, or after sending its parsed name => value array.
     *
     * @param mixed $headers
     */
    private static function yaymatik_mark( $headers ): string {
        $lines = is_array( $headers ) ? $headers : explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
        foreach ( $lines as $name => $line ) {
            $parts = is_string( $name ) ? [ $name, $line ] : explode( ':', (string) $line, 2 );
            if ( 2 === count( $parts ) && 0 === strcasecmp( trim( $parts[0] ), 'X-YayMatik-Mark' ) ) {
                return trim( (string) $parts[1] );
            }
        }
        return '';
    }
}
