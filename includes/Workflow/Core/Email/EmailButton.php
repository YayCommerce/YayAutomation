<?php
namespace YayMatik\Workflow\Core\Email;

defined( 'ABSPATH' ) || exit;

/** A button in the store's email color. A table with a padded link, as many mail clients ignore padding on a bare link. */
final class EmailButton {

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
        $table = 'small' === $style ? 'align="right" cellspacing="0" cellpadding="0" border="0" style="margin:0;"' : 'align="center" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto 16px;"';
        return '<table role="presentation" ' . $table . '><tr>' . self::cell( $label, $url, $style ) . '</tr></table>';
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
        return '<td align="center" bgcolor="' . esc_attr( $color ) . '" style="padding:0;background-color:' . esc_attr( $color ) . ';border-radius:4px;">'
            . '<a href="' . esc_url( $url ) . '" style="display:inline-block;' . $size . 'font-weight:bold;text-decoration:none;color:' . esc_attr( wc_light_or_dark( $color, '#202020', '#ffffff' ) ) . ';">' . esc_html( $label ) . '</a>'
            . '</td>';
    }
}
