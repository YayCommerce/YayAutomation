<?php
namespace YayMatik\Engine\FEPages;

use YayMatik\Utils\SingletonTrait;
use YayMatik\Workflow\Core\Cart\CartMerge;
use YayMatik\Workflow\Core\Cart\CartSnapshot;
use YayMatik\Workflow\Core\Cart\CartTracker;
use YayMatik\Workflow\Core\Cart\RestoreLink;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;
use YayMatik\Workflow\Core\Storage\CartStore;

defined( 'ABSPATH' ) || exit;

/** The restore link's request: adds the saved cart's items to the visitor's cart and opens checkout. Writes no carts row. */
class RestoreCart {

    use SingletonTrait;

    private const NOTICE_FLAG = 'yaymatik_restore_notice';

    protected function __construct() {
        // After WooCommerce loads the cart from the session (wp_loaded 10).
        add_action( 'wp_loaded', [ $this, 'maybe_handle' ], 20 );
        add_filter( 'render_block_woocommerce/checkout', [ $this, 'checkout_notices' ] );
    }

    public function maybe_handle(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the signed link is the proof; the shopper comes from an email.
        // A REST URL with the arg runs wp_loaded too, but WooCommerce loads no cart there.
        if ( ! isset( $_GET[ RestoreLink::ARG_CART ] ) || ! self::store_front_get() || ! WC()->cart || ! WC()->session ) {
            return;
        }
        $id    = absint( wp_unslash( $_GET[ RestoreLink::ARG_CART ] ) );
        $token = isset( $_GET[ UnsubscribeLink::ARG_TOKEN ] ) ? sanitize_text_field( wp_unslash( $_GET[ UnsubscribeLink::ARG_TOKEN ] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        CartTracker::suppress();
        nocache_headers();
        wc_maybe_define_constant( 'DONOTCACHEPAGE', true );
        // The token stays out of Referer headers on the next page.
        header( 'Referrer-Policy: no-referrer' );
        // WooCommerce starts a guest session cookie on `wp` / `template_redirect`, which never run here; without it
        // the session isn't saved and the cart and notices are lost on the redirect.
        if ( ! WC()->session->has_session() ) {
            WC()->session->set_customer_session_cookie( true );
        }
        wp_safe_redirect( $this->restore( $id, $token ) );
        exit;
    }

    /**
     * Merges the saved cart into the current one and adds the notice for the outcome.
     *
     * @param int    $id
     * @param string $token
     * @return string Where to send the shopper.
     */
    public function restore( int $id, string $token ): string {
        $shop = wc_get_page_permalink( 'shop' );
        $row  = $id > 0 ? CartStore::find( $id ) : null;
        if ( ! $row || ! RestoreLink::from_options()->verify( $id, (string) $row['created_at'], $token ) ) {
            wc_add_notice( __( 'This cart is no longer available.', 'yaymatik' ), 'notice' );
            return $shop;
        }
        if ( CartStore::ORDERED === $row['status'] ) {
            wc_add_notice( __( 'You already placed an order for these items.', 'yaymatik' ), 'notice' );
            return $shop;
        }

        $saved     = json_decode( (string) $row['items'], true );
        $saved     = is_array( $saved ) ? $saved : [];
        $available = [];
        foreach ( $saved as $line ) {
            $available[ CartSnapshot::line_key( $line ) ] = self::available( $line );
        }
        $current   = CartSnapshot::from_wc();
        $was_empty = WC()->cart->is_empty();
        $plan      = CartMerge::plan( $saved, $current ? $current->lines() : [], $available );

        // Only notices added while merging are dropped (WooCommerce's errors for a refused line); ours follow.
        $notices = wc_get_notices();
        $added   = 0;
        foreach ( $plan['add'] as $item ) {
            $added += self::add( $item['line'], $item['quantity'] ) ? 1 : 0;
        }
        wc_set_notices( $notices );
        $skipped = $plan['skipped'] + count( $plan['add'] ) - $added;

        if ( 0 === $added && 0 === $plan['kept'] ) {
            wc_add_notice( __( 'This cart is no longer available.', 'yaymatik' ), 'notice' );
            return $shop;
        }
        if ( $added > 0 ) {
            WC()->cart->calculate_totals();
            self::prefill( $row, $was_empty );
            wc_add_notice( __( 'We added your saved items to your cart.', 'yaymatik' ), 'success' );
        }
        if ( $skipped > 0 ) {
            wc_add_notice( __( 'Some items are no longer available.', 'yaymatik' ), 'notice' );
        }
        if ( $added > 0 || $skipped > 0 ) {
            WC()->session->set( self::NOTICE_FLAG, true );
        }
        CartTracker::restored( $id );
        return wc_get_checkout_url();
    }

    /**
     * The restore notices above the Checkout block.
     *
     * @param string $content
     */
    public function checkout_notices( $content ) {
        // The Checkout block prints no session notices, and a classic theme skips them on checkout. A block theme's
        // store-notices block prints and clears them first, so nothing shows twice.
        if ( ! WC()->session || ! WC()->session->get( self::NOTICE_FLAG ) ) {
            return $content;
        }
        WC()->session->set( self::NOTICE_FLAG, null );
        $notices = wc_print_notices( true );
        return '' === trim( (string) $notices ) ? $content : '<div class="woocommerce-notices-wrapper">' . $notices . '</div>' . $content;
    }

    /**
     * @param array $line A saved cart line.
     */
    private static function available( array $line ): bool {
        $variation_id = (int) $line['variation_id'];
        $product      = wc_get_product( $variation_id > 0 ? $variation_id : (int) $line['product_id'] );
        if ( ! $product instanceof \WC_Product || 'trash' === $product->get_status() || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
            return false;
        }
        // add_to_cart() reads the parent's attributes without checking that it exists: an Error its catch doesn't take.
        return ! $product->is_type( 'variation' ) || wc_get_product( $product->get_parent_id() ) instanceof \WC_Product;
    }

    /**
     * Adds to the cart's line when there is one, so WooCommerce checks the stock already in the cart.
     *
     * @param array $line A saved cart line.
     * @param int   $quantity
     */
    private static function add( array $line, int $quantity ): bool {
        $product_id   = (int) $line['product_id'];
        $variation_id = (int) $line['variation_id'];
        $variation    = (array) ( $line['variation'] ?? [] );
        // Same filter as WooCommerce's add-to-cart form: other plugins may refuse a product here.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, applied as its add-to-cart form does.
        if ( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $variation ) ) {
            return false;
        }
        return false !== WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
    }

    /**
     * Fills the guest's checkout with the saved email and first name.
     *
     * @param array $row
     * @param bool  $was_empty The cart before the merge.
     */
    private static function prefill( array $row, bool $was_empty ): void {
        $customer = WC()->customer;
        // A page on another site can make a browser open a valid link: an address goes only into an empty guest checkout,
        // never into a shopper's own cart or account.
        if ( ! $was_empty || is_user_logged_in() || 0 !== (int) $row['user_id'] || '' === $row['email']
            || ! $customer instanceof \WC_Customer || '' !== (string) $customer->get_billing_email() ) {
            return;
        }
        $customer->set_billing_email( $row['email'] );
        if ( '' === (string) $customer->get_billing_first_name() ) {
            $customer->set_billing_first_name( $row['first_name'] );
        }
        $customer->save();
    }

    /** Store-front GET or HEAD: not wp-admin, AJAX, cron or wp-cli. */
    private static function store_front_get(): bool {
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
        return in_array( $method, [ 'GET', 'HEAD' ], true ) && ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI );
    }
}
