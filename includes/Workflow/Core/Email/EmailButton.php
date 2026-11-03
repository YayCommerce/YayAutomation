<?php
namespace YayMatik\Workflow\Core\Email;

defined( 'ABSPATH' ) || exit;

/** A button in the store's email color. A table with a padded link, as many mail clients ignore padding on a bare link. */
final class EmailButton {

    private const CENTERED = 'align="center" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto 16px;"';

    /**
     * Text: "Label: URL". Style `solid`, or `small`: no outer margin, for a table cell.
     *
     * @param string $label
     * @param string $url
     * @param bool   $html
     * @param string $style
     */
    public static function render( string $label, string $url, bool $html, string $style = 'solid' ): string {
        if ( ! $html ) {
            return $label . ': ' . $url;
        }
        $table = 'small' === $style ? 'align="right" cellspacing="0" cellpadding="0" border="0" style="margin:0;"' : self::CENTERED;
        return '<table role="presentation" ' . $table . '><tr>' . self::cell( $label, $url, $style ) . '</tr></table>';
    }

    /**
     * A solid and an outline button side by side, centered. Text: one line each.
     *
     * @param string $solid_label
     * @param string $solid_url
     * @param string $outline_label
     * @param string $outline_url
     * @param bool   $html
     */
    public static function pair( string $solid_label, string $solid_url, string $outline_label, string $outline_url, bool $html ): string {
        if ( ! $html ) {
            return self::render( $solid_label, $solid_url, false ) . "\n" . self::render( $outline_label, $outline_url, false );
        }
        return '<table role="presentation" ' . self::CENTERED . '><tr>'
            . self::cell( $solid_label, $solid_url, 'solid' )
            . '<td width="8" style="width:8px;padding:0;">&nbsp;</td>'
            . self::cell( $outline_label, $outline_url, 'outline' )
            . '</tr></table>';
    }

    /**
     * @param string $label
     * @param string $url
     * @param string $style
     */
    private static function cell( string $label, string $url, string $style ): string {
        $color = sanitize_hex_color( (string) get_option( 'woocommerce_email_base_color', '' ) );
        $color = $color ? $color : '#7f54b3';
        $size  = 'small' === $style ? 'padding:6px 12px;font-size:13px;' : 'padding:12px 24px;';
        $fill  = 'outline' === $style ? '#ffffff' : $color;
        // On white, a light store color would hide the outline button's label and border.
        $ink  = wc_light_or_dark( $color, '#202020', $color );
        $text = 'outline' === $style ? $ink : wc_light_or_dark( $color, '#202020', '#ffffff' );
        $edge = 'outline' === $style ? 'border:1px solid ' . esc_attr( $ink ) . ';' : '';
        return '<td align="center" bgcolor="' . esc_attr( $fill ) . '" style="padding:0;background-color:' . esc_attr( $fill ) . ';' . $edge . 'border-radius:4px;">'
            . '<a href="' . esc_url( $url ) . '" style="display:inline-block;' . $size . 'font-weight:bold;text-decoration:none;color:' . esc_attr( $text ) . ';">' . esc_html( $label ) . '</a>'
            . '</td>';
    }
}
