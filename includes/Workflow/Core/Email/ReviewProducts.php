<?php
namespace YayMatik\Workflow\Core\Email;

defined( 'ABSPATH' ) || exit;

/** An order's products the customer hasn't reviewed, read when the step runs, and the block that asks for a review of each. */
final class ReviewProducts {

    private const MAX = 6;

    /**
     * Published parents (a variation counts as its parent) open for reviews and not reviewed yet; none while the store has reviews off.
     *
     * @param \WC_Order $order
     * @return \WC_Product[] At most MAX.
     */
    public static function for_order( \WC_Order $order ): array {
        if ( 'yes' !== get_option( 'woocommerce_enable_reviews', 'yes' ) ) {
            return [];
        }
        $items = array_filter( $order->get_items(), fn( $item ) => $item instanceof \WC_Order_Item_Product );
        _prime_post_caches( array_values( array_unique( array_map( fn( $item ) => (int) $item->get_product_id(), $items ) ) ) );
        $products = [];
        foreach ( $items as $item ) {
            $id = (int) $item->get_product_id();
            // A refund's quantity is negative. A returned line isn't worth asking about, though the order stays completed.
            if ( $id <= 0 || isset( $products[ $id ] ) || $item->get_quantity() + $order->get_qty_refunded_for_item( $item->get_id() ) <= 0 ) {
                continue;
            }
            $product = wc_get_product( $id );
            // Hidden from the catalog: the store doesn't show it, so the email doesn't either.
            if ( $product && 'publish' === $product->get_status() && 'hidden' !== $product->get_catalog_visibility() && $product->get_reviews_allowed() ) {
                $products[ $id ] = $product;
            }
        }
        $reviewed = self::reviewed_ids( array_keys( $products ), (string) $order->get_billing_email(), (int) $order->get_customer_id() );
        return array_slice( array_values( array_diff_key( $products, array_flip( $reviewed ) ) ), 0, self::MAX );
    }

    /**
     * Which of these products have an approved or held review by this email or user.
     *
     * @param int[]  $ids
     * @param string $email
     * @param int    $user_id
     * @return int[]
     */
    public static function reviewed_ids( array $ids, string $email, int $user_id ): array {
        // get_comments() ignores an empty `post__in` or `author_email`, and `user_id` 0 matches every guest: any of them would mark everything reviewed.
        if ( ! $ids ) {
            return [];
        }
        $query    = [
            'post__in'                  => $ids,
            'type'                      => 'review',
            // WooCommerce stores a reply to a review as a review too.
            'parent'                    => 0,
            'status'                    => [ 'approve', 'hold' ],
            'update_comment_meta_cache' => false,
            'update_comment_post_cache' => false,
        ];
        $comments = [];
        // Two queries, as WP_Comment_Query ANDs these two; one query by product would load every review of a popular one.
        if ( '' !== $email ) {
            $comments = array_merge( $comments, get_comments( $query + [ 'author_email' => $email ] ) );
        }
        if ( $user_id > 0 ) {
            $comments = array_merge( $comments, get_comments( $query + [ 'user_id' => $user_id ] ) );
        }
        return array_values( array_unique( array_map( fn( $comment ) => (int) $comment->comment_post_ID, $comments ) ) );
    }

    /**
     * Table in HTML, on one line: the email body goes through wpautop(), which would put <br> between table rows. No products → ''.
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
                fn( \WC_Product $product ) => html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ) . ' — ' . EmailButton::render( self::label(), self::url( $product ), false ),
                $products
            );
            return implode( "\n", $lines );
        }
        // Inline padding and margins throughout: WooCommerce inlines its email CSS (`td td` padding, `img` margin-right) into anything that doesn't set its own.
        return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 16px;">' . implode( '', array_map( [ self::class, 'row' ], $products ) ) . '</table>';
    }

    /**
     * @param \WC_Product $product
     */
    private static function row( \WC_Product $product ): string {
        $name = esc_html( ProductGrid::short_name( $product->get_name() ) );
        $link = esc_url( $product->get_permalink() );
        return '<tr><td width="' . CartItems::IMAGE . '" style="width:' . CartItems::IMAGE . 'px;padding:0 12px 12px 0;vertical-align:middle;"><a href="' . $link . '">' . CartItems::image( $product, $name ) . '</a></td>'
            . '<td style="padding:0 12px 12px 0;vertical-align:middle;"><a href="' . $link . '" style="font-weight:bold;text-decoration:none;">' . $name . '</a></td>'
            . '<td style="padding:0 0 12px;vertical-align:middle;text-align:right;white-space:nowrap;">' . EmailButton::render( self::label(), self::url( $product ), true, 'small' ) . '</td></tr>';
    }

    private static function label(): string {
        return __( 'Leave a review', 'yaymatik' );
    }

    /**
     * @param \WC_Product $product
     */
    private static function url( \WC_Product $product ): string {
        return $product->get_permalink() . '#reviews';
    }
}
