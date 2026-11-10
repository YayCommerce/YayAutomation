<?php
namespace YayMatik\Workflow\Core\Email;

defined( 'ABSPATH' ) || exit;

/** Suggested products for an email: the order's cross-sells or upsells, drawn as a grid. */
final class ProductGrid {

    public const CROSS_SELLS = 'cross_sells';
    public const UPSELLS     = 'upsells';

    /** At most this many products, 2 per row. */
    public const MAX = 4;

    /** Products loaded per query: some may be hidden or out of stock. */
    private const FETCH = self::MAX * 3;

    /** Suggestions looked at in all, in FETCH-sized queries until MAX can be shown. */
    private const SCAN = self::FETCH * 3;

    /** Each image sits in a square this many pixels wide. */
    private const IMAGE_BOX = 160;

    /** Longer names are cut, with "…". */
    private const NAME_MAX = 60;

    /** @var \WC_Product[]|null The store's newest products. */
    private static $newest = null;

    /**
     * Cross-sells or upsells of the order's products (a variation counts as its parent), minus what the order has.
     *
     * @param \WC_Order $order
     * @param string    $kind  CROSS_SELLS or UPSELLS.
     * @return \WC_Product[] Published, visible, in stock; at most MAX.
     */
    public static function for_order( \WC_Order $order, string $kind ): array {
        $in_order  = [];
        $suggested = [];
        $lines     = array_filter( $order->get_items(), fn( $item ) => $item instanceof \WC_Order_Item_Product );
        $line_ids  = [];
        foreach ( $lines as $item ) {
            $line_ids[] = (int) $item->get_product_id();
            $line_ids[] = (int) $item->get_variation_id();
        }
        _prime_post_caches( array_values( array_unique( array_filter( $line_ids ) ) ) );
        foreach ( $lines as $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }
            $parent     = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
            $in_order[] = $product->get_id();
            if ( ! $parent ) {
                continue;
            }
            $in_order[] = $parent->get_id();
            $suggested  = array_merge( $suggested, self::CROSS_SELLS === $kind ? $parent->get_cross_sell_ids() : $parent->get_upsell_ids() );
        }

        $ids   = array_slice( array_values( array_diff( array_unique( array_map( 'intval', $suggested ) ), $in_order ) ), 0, self::SCAN );
        $shown = [];
        foreach ( array_chunk( $ids, self::FETCH ) as $chunk ) {
            _prime_post_caches( $chunk );
            $shown = self::shown( array_merge( $shown, array_filter( array_map( 'wc_get_product', $chunk ) ) ) );
            if ( count( $shown ) >= self::MAX ) {
                break;
            }
        }
        return $shown;
    }

    /**
     * The first MAX that are published, visible and in stock.
     *
     * @param \WC_Product[] $products
     * @return \WC_Product[]
     */
    private static function shown( array $products ): array {
        $shown = array_filter( $products, fn( \WC_Product $product ) => 'publish' === $product->get_status() && $product->is_visible() && $product->is_in_stock() );
        return array_slice( array_values( $shown ), 0, self::MAX );
    }

    /**
     * The store's newest products a grid would show, read once per request: `site.new_products`, and a preview with sample data.
     *
     * @return \WC_Product[]
     */
    public static function newest(): array {
        if ( null === self::$newest ) {
            $products     = function_exists( 'wc_get_products' )
                ? (array) wc_get_products(
                    [
                        'status'       => 'publish',
                        'visibility'   => 'catalog',
                        'stock_status' => [ 'instock', 'onbackorder' ],
                        'limit'        => self::FETCH,
                        'orderby'      => 'date',
                        'order'        => 'DESC',
                    ]
                )
                : [];
            self::$newest = self::shown( $products );
        }
        return self::$newest;
    }

    /**
     * An email-safe table, 2 per row, an odd last one centered; in text one line each, "Name — price — URL". None → ''.
     *
     * @param \WC_Product[] $products
     * @param bool          $html
     */
    public static function render( array $products, bool $html ): string {
        if ( ! $products ) {
            return '';
        }
        if ( ! $html ) {
            $lines = array_map(
                fn( \WC_Product $product ) => implode( ' — ', [ $product->get_name(), self::plain( $product->get_price_html() ), $product->get_permalink() ] ),
                $products
            );
            return implode( "\n", $lines );
        }

        $rows = '';
        foreach ( array_chunk( $products, 2 ) as $i => $pair ) {
            $top = $i ? 16 : 0;
            if ( 1 === count( $pair ) ) {
                $rows .= '<tr><td colspan="2" style="padding:' . $top . 'px 0 0;vertical-align:top;">'
                    . '<table role="presentation" width="50%" align="center" cellspacing="0" cellpadding="0" border="0" style="width:50%;margin:0 auto;"><tr><td style="padding:0 8px;vertical-align:top;text-align:center;">'
                    . self::cell( $pair[0] ) . '</td></tr></table></td></tr>';
                continue;
            }
            $cells = '';
            foreach ( [ "{$top}px 8px 0 0", "{$top}px 0 0 8px" ] as $side => $padding ) {
                $cells .= '<td width="50%" style="width:50%;padding:' . $padding . ';vertical-align:top;text-align:center;">' . self::cell( $pair[ $side ] ) . '</td>';
            }
            $rows .= '<tr>' . $cells . '</tr>';
        }
        // One line: the email body goes through wpautop(), which would put <br> between table rows. Same
        // width and margin as the coupon box.
        return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 16px;">' . $rows . '</table>';
    }

    /**
     * Image in a fixed square, name (shortened), price.
     *
     * @param \WC_Product $product
     */
    private static function cell( \WC_Product $product ): string {
        $url   = esc_url( $product->get_permalink() );
        $image = $product->get_image_id() ? wp_get_attachment_image_src( $product->get_image_id(), 'woocommerce_thumbnail' ) : false;
        if ( $image ) {
            list( $src, $width, $height ) = $image;
        } else {
            $size   = wc_get_image_size( 'woocommerce_thumbnail' );
            $src    = wc_placeholder_img_src( 'woocommerce_thumbnail' );
            $width  = $size['width'];
            $height = $size['height'];
        }
        $size = self::fit( (int) $width, (int) $height );
        $box  = self::IMAGE_BOX;
        // A size it doesn't know is left to the image (height auto), not forced square.
        $dimensions = $size ? 'width="' . $size[0] . '" height="' . $size[1] . '"' : 'width="' . $box . '"';
        // The square and the image shrink with a narrow column (phones); the attributes are the desktop size.
        // Inline padding and margins throughout: WooCommerce inlines its email CSS (`td td` padding,
        // `img` margin-right, `p` margins) into anything that doesn't set its own.
        return '<table role="presentation" align="center" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:' . $box . 'px;margin:0 auto;"><tr>'
            . '<td height="' . $box . '" align="center" valign="middle" style="height:' . $box . 'px;padding:0;background-color:#f4f4f5;text-align:center;vertical-align:middle;">'
            . '<a href="' . $url . '"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $product->get_name() ) . '" ' . $dimensions . ' style="display:inline-block;max-width:100%;height:auto;margin:0;border:0;vertical-align:middle;"></a>'
            . '</td></tr></table>'
            . '<p style="margin:8px 0 2px;font-size:14px;line-height:20px;"><a href="' . $url . '" style="font-weight:bold;text-decoration:none;">' . esc_html( self::short_name( $product->get_name() ) ) . '</a></p>'
            . '<p style="margin:0;font-size:14px;line-height:20px;color:#767676;">' . wp_kses_post( $product->get_price_html() ) . '</p>';
    }

    /**
     * An image's size scaled down to fit the square, ratio kept; never scaled up.
     *
     * @param int $width
     * @param int $height
     * @return int[]|null [ width, height ]; null when the size isn't known.
     */
    private static function fit( int $width, int $height ): ?array {
        if ( $width <= 0 || $height <= 0 ) {
            return null;
        }
        $scale = min( 1, self::IMAGE_BOX / max( $width, $height ) );
        return [ max( 1, (int) round( $width * $scale ) ), max( 1, (int) round( $height * $scale ) ) ];
    }

    /**
     * Cut to about two lines at 14px in a half-width cell.
     *
     * @param string $name
     */
    public static function short_name( string $name ): string {
        // Cut the text, not its entities (`&amp;`): saved names can hold them.
        $name = html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
        return mb_strlen( $name ) > self::NAME_MAX ? rtrim( mb_substr( $name, 0, self::NAME_MAX - 1 ) ) . '…' : $name;
    }

    private static function plain( string $html ): string {
        return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
    }
}
