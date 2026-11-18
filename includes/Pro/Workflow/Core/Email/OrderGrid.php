<?php
namespace YayMatik\Pro\Workflow\Core\Email;

use YayMatik\Workflow\Core\Email\ProductGrid;

defined( 'ABSPATH' ) || exit;

/** An order's cross-sells or upsells, for a `ProductGrid`. */
final class OrderGrid {

    public const CROSS_SELLS = 'cross_sells';
    public const UPSELLS     = 'upsells';

    /** Suggestions looked at in all, in FETCH-sized queries until MAX can be shown. */
    private const SCAN = ProductGrid::FETCH * 3;

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
        foreach ( array_chunk( $ids, ProductGrid::FETCH ) as $chunk ) {
            _prime_post_caches( $chunk );
            $shown = ProductGrid::shown( array_merge( $shown, array_filter( array_map( 'wc_get_product', $chunk ) ) ) );
            if ( count( $shown ) >= ProductGrid::MAX ) {
                break;
            }
        }
        return $shown;
    }

    /**
     * What a grid block shows: `for_order()`, or in a preview without one, the newest products not in the order.
     *
     * @param \WC_Order $order
     * @param string    $kind
     * @return \WC_Product[]
     */
    public static function for_block( \WC_Order $order, string $kind ): array {
        $shown = self::for_order( $order, $kind );
        if ( $shown || ! ProductGrid::newest_when_empty() ) {
            return $shown;
        }
        $in_order = [];
        foreach ( $order->get_items() as $item ) {
            if ( $item instanceof \WC_Order_Item_Product ) {
                $in_order[] = (int) $item->get_product_id();
                $in_order[] = (int) $item->get_variation_id();
            }
        }
        return array_values( array_filter( ProductGrid::newest(), fn( \WC_Product $product ) => ! in_array( $product->get_id(), $in_order, true ) ) );
    }
}
