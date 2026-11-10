<?php
namespace YayMatik\Workflow\Core\Email;

use YayMatik\Workflow\Core\Cart\SavedCart;

defined( 'ABSPATH' ) || exit;

/** A saved cart in an email: its products with the prices the shopper saw, and the restore button. */
final class CartItems {

    public const IMAGE = 64;

    /** Lines listed; the rest are counted in one line, and the total still covers them. */
    public const MAX_LINES = 50;

    /**
     * Table in HTML, on one line: the email body goes through wpautop(), which would put <br> between table rows. No lines → ''.
     *
     * @param SavedCart $cart
     * @param bool      $html
     */
    public static function render( SavedCart $cart, bool $html ): string {
        $all = $cart->lines();
        if ( ! $all ) {
            return '';
        }
        $lines = array_slice( $all, 0, self::MAX_LINES );
        $more  = (int) array_sum( array_map( fn( array $line ) => max( 1, (int) ( $line['quantity'] ?? 1 ) ), array_slice( $all, self::MAX_LINES ) ) );
        /* translators: %d: number of items in the cart lines not listed */
        $more_text = $more ? sprintf( _n( 'and %d more item', 'and %d more items', $more, 'yaymatik' ), $more ) : '';
        _prime_post_caches( self::product_ids( $lines ) );
        $total = __( 'Cart total', 'yaymatik' );
        if ( ! $html ) {
            $text = array_map(
                fn( array $line ) => sprintf( '%s × %d — %s', self::name( $line, self::product( $line ) ), (int) ( $line['quantity'] ?? 0 ), $cart->price( (float) ( $line['line_total'] ?? 0 ), false ) ),
                $lines
            );
            return implode( "\n", array_merge( $text, array_filter( [ $more_text ] ), [ $total . ': ' . $cart->price( $cart->total(), false ) ] ) );
        }
        $rows = implode( '', array_map( fn( array $line ) => self::row( $cart, $line ), $lines ) );
        if ( $more_text ) {
            $rows .= '<tr><td colspan="3" style="padding:0 0 12px;color:#71717a;">' . esc_html( $more_text ) . '</td></tr>';
        }
        // Inline padding and margins throughout: WooCommerce inlines its email CSS (`td td` padding, `img` margin-right) into anything that doesn't set its own.
        return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 16px;">' . $rows
            . '<tr><td colspan="2" style="padding:12px 12px 0 0;border-top:1px solid #e4e4e7;font-weight:bold;">' . esc_html( $total ) . '</td>'
            . '<td style="padding:12px 0 0;border-top:1px solid #e4e4e7;font-weight:bold;text-align:right;white-space:nowrap;">' . $cart->price( $cart->total(), true ) . '</td></tr></table>';
    }

    /**
     * @param SavedCart $cart
     * @param bool      $html
     */
    public static function button( SavedCart $cart, bool $html ): string {
        return EmailButton::render( __( 'Restore my cart', 'yaymatik' ), $cart->restore_url(), $html );
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
    public static function image( ?\WC_Product $product, string $alt ): string {
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
     * Products and variations of these lines, so their posts load in one query.
     *
     * @param array[] $lines
     * @return int[]
     */
    private static function product_ids( array $lines ): array {
        $ids = [];
        foreach ( $lines as $line ) {
            $ids[] = (int) ( $line['product_id'] ?? 0 );
            $ids[] = (int) ( $line['variation_id'] ?? 0 );
        }
        return array_values( array_unique( array_filter( $ids ) ) );
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
