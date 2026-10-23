<?php
namespace YayMatik\Workflow\Core\Email;

defined( 'ABSPATH' ) || exit;

/** Suggested products for an email: the order's cross-sells or upsells, drawn as a grid. */
final class ProductGrid {

    public const CROSS_SELLS = 'cross_sells';
    public const UPSELLS     = 'upsells';

    /** At most this many products, 2 per row. */
    public const MAX = 4;

    /** @var \WC_Product[]|null The store's newest products, for previews. */
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
        foreach ( $order->get_items() as $item ) {
            $product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
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

        $ids = array_diff( array_unique( array_map( 'intval', $suggested ) ), $in_order );
        return self::shown( array_filter( array_map( 'wc_get_product', $ids ) ) );
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
     * The store's newest products a grid would show, for a preview with sample data; read once per request.
     *
     * @return \WC_Product[]
     */
    public static function newest(): array {
        if ( null === self::$newest ) {
            // A few extra, as some may be hidden or out of stock.
            $products     = function_exists( 'wc_get_products' )
                ? (array) wc_get_products(
                    [
                        'status'  => 'publish',
                        'limit'   => self::MAX * 3,
                        'orderby' => 'date',
                        'order'   => 'DESC',
                    ]
                )
                : [];
            self::$newest = self::shown( $products );
        }
        return self::$newest;
    }

    /**
     * An email-safe table, 2 per row (image, name, price, link); in text one line each, "Name — price — URL". None → ''.
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
        foreach ( array_chunk( $products, 2 ) as $pair ) {
            $cells = array_map( [ self::class, 'cell' ], $pair );
            if ( 1 === count( $cells ) ) {
                $cells[] = '<td style="width:50%;padding:8px;"></td>';
            }
            $rows .= '<tr>' . implode( '', $cells ) . '</tr>';
        }
        // One line: the email body goes through wpautop(), which would put <br> between table rows.
        return '<table cellspacing="0" cellpadding="0" border="0" style="width:100%;"><tbody>' . $rows . '</tbody></table>';
    }

    /**
     * @param \WC_Product $product
     */
    private static function cell( \WC_Product $product ): string {
        $url   = esc_url( $product->get_permalink() );
        $name  = esc_html( $product->get_name() );
        $image = $product->get_image_id() ? wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) : '';
        $image = $image ? $image : wc_placeholder_img_src( 'woocommerce_thumbnail' );
        return '<td style="width:50%;padding:8px;vertical-align:top;text-align:center;">'
            . '<a href="' . $url . '"><img src="' . esc_url( $image ) . '" alt="' . esc_attr( $product->get_name() ) . '" width="200" style="display:block;width:100%;max-width:200px;height:auto;margin:0 auto 8px;border:0;"></a>'
            . '<a href="' . $url . '" style="font-weight:bold;text-decoration:none;">' . $name . '</a>'
            . '<div>' . wp_kses_post( $product->get_price_html() ) . '</div>'
            . '</td>';
    }

    private static function plain( string $html ): string {
        return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
    }
}
