<?php
namespace YayMatik\Workflow\Core\Email;

use YayMatik\Workflow\Core\Cart\SavedCart;

defined( 'ABSPATH' ) || exit;

/** A saved cart in an email: its products with the prices the shopper saw, and the restore button. */
final class CartItems {

    private const IMAGE = 64;

    /**
     * Table in HTML, on one line: the email body goes through wpautop(), which would put <br> between table rows. No lines → ''.
     *
     * @param SavedCart $cart
     * @param bool      $html
     */
    public static function render( SavedCart $cart, bool $html ): string {
        $lines = $cart->lines();
        if ( ! $lines ) {
            return '';
        }
        $total = __( 'Cart total', 'yaymatik' );
        if ( ! $html ) {
            $text = array_map(
                fn( array $line ) => sprintf( '%s × %d — %s', self::name( $line, self::product( $line ) ), (int) ( $line['quantity'] ?? 0 ), $cart->price( (float) ( $line['line_total'] ?? 0 ), false ) ),
                $lines
            );
            return implode( "\n", array_merge( $text, [ $total . ': ' . $cart->price( $cart->total(), false ) ] ) );
        }
        $rows = implode( '', array_map( fn( array $line ) => self::row( $cart, $line ), $lines ) );
        // Inline padding and margins throughout: WooCommerce inlines its email CSS (`td td` padding, `img` margin-right) into anything that doesn't set its own.
        return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 16px;">' . $rows
            . '<tr><td colspan="2" style="padding:12px 12px 0 0;border-top:1px solid #e4e4e7;font-weight:bold;">' . esc_html( $total ) . '</td>'
            . '<td style="padding:12px 0 0;border-top:1px solid #e4e4e7;font-weight:bold;text-align:right;white-space:nowrap;">' . $cart->price( $cart->total(), true ) . '</td></tr></table>';
    }

    /**
     * A button built as a table with a padded link, as many mail clients ignore padding on a bare link.
     *
     * @param SavedCart $cart
     * @param bool      $html
     */
    public static function button( SavedCart $cart, bool $html ): string {
        $label = __( 'Restore my cart', 'yaymatik' );
        $url   = $cart->restore_url();
        if ( ! $html ) {
            return $label . ': ' . $url;
        }
        $color = sanitize_hex_color( (string) get_option( 'woocommerce_email_base_color', '' ) );
        $color = $color ? $color : '#7f54b3';
        return '<table role="presentation" align="center" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto 16px;"><tr>'
            . '<td align="center" bgcolor="' . esc_attr( $color ) . '" style="padding:0;background-color:' . esc_attr( $color ) . ';border-radius:4px;">'
            . '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 24px;font-weight:bold;text-decoration:none;color:' . esc_attr( wc_light_or_dark( $color, '#202020', '#ffffff' ) ) . ';">' . esc_html( $label ) . '</a>'
            . '</td></tr></table>';
    }

    /**
     * Live name, image and link while the product is on sale; money always from the snapshot.
     *
     * @param SavedCart $cart
     * @param array     $line
     */
    private static function row( SavedCart $cart, array $line ): string {
        $product = self::product( $line );
        $name    = esc_html( self::name( $line, $product ) );
        $image   = self::image( $product, $name );
        if ( $product ) {
            $url   = esc_url( $product->get_permalink() );
            $image = '<a href="' . $url . '">' . $image . '</a>';
            $name  = '<a href="' . $url . '" style="font-weight:bold;text-decoration:none;">' . $name . '</a>';
        }
        /* translators: %d: quantity of a product in the cart */
        $quantity = sprintf( __( 'Qty: %d', 'yaymatik' ), (int) ( $line['quantity'] ?? 0 ) );
        return '<tr><td width="' . self::IMAGE . '" style="width:' . self::IMAGE . 'px;padding:0 12px 12px 0;vertical-align:top;">' . $image . '</td>'
            . '<td style="padding:0 12px 12px 0;vertical-align:top;"><p style="margin:0;font-weight:bold;">' . $name . '</p>'
            . '<p style="margin:2px 0 0;font-size:13px;color:#767676;">' . esc_html( $quantity ) . '</p></td>'
            . '<td style="padding:0 0 12px;vertical-align:top;text-align:right;white-space:nowrap;">' . $cart->price( (float) ( $line['line_total'] ?? 0 ), true ) . '</td></tr>';
    }

    /**
     * @param \WC_Product|null $product
     * @param string           $alt     Escaped.
     */
    private static function image( ?\WC_Product $product, string $alt ): string {
        $image = $product && $product->get_image_id() ? wp_get_attachment_image_src( $product->get_image_id(), 'woocommerce_thumbnail' ) : false;
        $src   = $image ? $image[0] : wc_placeholder_img_src( 'woocommerce_thumbnail' );
        return '<img src="' . esc_url( $src ) . '" alt="' . $alt . '" width="' . self::IMAGE . '" style="display:block;width:' . self::IMAGE . 'px;max-width:100%;height:auto;margin:0;border:0;background-color:#f4f4f5;">';
    }

    /**
     * Live name, or the snapshot's once the product is gone. Decoded: saved names can hold entities.
     *
     * @param array            $line
     * @param \WC_Product|null $product
     */
    private static function name( array $line, ?\WC_Product $product ): string {
        return html_entity_decode( $product ? $product->get_name() : (string) ( $line['name'] ?? '' ), ENT_QUOTES, 'UTF-8' );
    }

    /**
     * The line's product, while it and a variation's parent exist and are published: the link goes to the parent's page.
     *
     * @param array $line
     */
    private static function product( array $line ): ?\WC_Product {
        $id      = (int) ( $line['variation_id'] ?? 0 );
        $id      = $id > 0 ? $id : (int) ( $line['product_id'] ?? 0 );
        $product = $id > 0 ? wc_get_product( $id ) : false;
        if ( ! $product || 'publish' !== $product->get_status() ) {
            return null;
        }
        if ( ! $product->is_type( 'variation' ) ) {
            return $product;
        }
        $parent = wc_get_product( $product->get_parent_id() );
        return $parent && 'publish' === $parent->get_status() ? $product : null;
    }
}
