<?php
/**
 * YayMagic's fake adapter: sample designs, no YayMagic code.
 *
 * @package YayMagic
 */

defined( 'ABSPATH' ) || exit;

/** Stands in for YayMagic until its real adapter is written. */
final class YayMagic_YayMatik_Fake_Adapter {

    /** YayMagic's own behavior: it wraps every email in its design, but not one YayMatik marked. */
    public function hook_mail(): void {
        add_filter( 'wp_mail', [ $this, 'wrap' ] );
    }

    /**
     * @param array $atts
     */
    public function wrap( $atts ) {
        if ( '' === self::yaymatik_mark( $atts['headers'] ?? '' ) ) {
            $atts['message'] = '<html><body data-wrapper="yaymagic">' . $atts['message'] . '</body></html>';
        }
        return $atts;
    }

    /** Design id => name. */
    public function design_options(): array {
        return [
            '1' => 'Welcome',
            '2' => 'We miss you',
            '3' => 'Milestone',
        ];
    }

    /**
     * Subject and HTML for any trigger, with or without an order; YayMatik fills the merge tags and wraps it.
     *
     * @param array              $config
     * @param \YayMatik\Api\Step $step
     */
    public function build_email( array $config, $step ): array {
        $design = $this->design_options()[ (string) ( $config['design_id'] ?? '' ) ] ?? 'Welcome';
        return [
            'subject' => (string) $config['subject'],
            'html'    => '<p>Hi {{ customer.first_name | default: "there" }},</p>'
                . '<p>' . esc_html( 'YayMagic design: ' . $design ) . '</p>'
                . '<p>{{ site.shop_button }}</p>',
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
