<?php
namespace YayMatik\Workflow\Core\Cart;

defined( 'ABSPATH' ) || exit;

/** A cart's lines, total and currency at one moment. */
final class CartSnapshot {

    /** @var array[] */
    private $lines;

    /** @var float */
    private $total;

    /** @var string */
    private $currency;

    /**
     * @param array[] $lines
     * @param float   $total
     * @param string  $currency
     */
    private function __construct( array $lines, float $total, string $currency ) {
        $this->lines    = $lines;
        $this->total    = $total;
        $this->currency = $currency;
    }

    /** The current WooCommerce cart; null when it isn't loaded. */
    public static function from_wc(): ?self {
        $cart = function_exists( 'WC' ) ? WC()->cart : null;
        if ( ! $cart instanceof \WC_Cart ) {
            return null;
        }
        $incl  = $cart->display_prices_including_tax();
        $lines = [];
        foreach ( $cart->get_cart() as $item ) {
            $product = $item['data'] ?? null;
            if ( ! $product instanceof \WC_Product ) {
                continue;
            }
            $lines[] = [
                'product_id'   => (int) $item['product_id'],
                'variation_id' => (int) $item['variation_id'],
                'quantity'     => (int) $item['quantity'],
                'variation'    => (array) ( $item['variation'] ?? [] ),
                'name'         => $product->get_name(),
                'price'        => (float) ( $incl ? wc_get_price_including_tax( $product ) : wc_get_price_excluding_tax( $product ) ),
                'line_total'   => self::line_total( $item, $incl ),
            ];
        }
        return self::from_lines( $lines, (float) $cart->get_total( 'edit' ), get_woocommerce_currency() );
    }

    /**
     * @param array[] $lines product_id, variation_id, quantity, variation, name, price, line_total.
     * @param float   $total
     * @param string  $currency
     */
    public static function from_lines( array $lines, float $total, string $currency ): self {
        return new self( array_values( $lines ), $total, $currency );
    }

    /**
     * Price × quantity as the cart table shows it: before coupons, with tax when prices show it.
     *
     * @param array $item A WooCommerce cart item.
     * @param bool  $incl
     */
    public static function line_total( array $item, bool $incl ): float {
        return (float) ( $item['line_subtotal'] ?? 0 ) + ( $incl ? (float) ( $item['line_subtotal_tax'] ?? 0 ) : 0.0 );
    }

    public function lines(): array {
        return $this->lines;
    }

    public function total(): float {
        return $this->total;
    }

    public function currency(): string {
        return $this->currency;
    }

    /** What the shopper chose: products, variations, quantities and currency. Prices are left out, so a price or tax refresh isn't a change. */
    public function hash(): string {
        $keys = array_map(
            function ( array $line ): string {
                $variation = array_map( 'strval', (array) $line['variation'] );
                ksort( $variation );
                return (string) wp_json_encode( [ (int) $line['product_id'], (int) $line['variation_id'], (int) $line['quantity'], $variation ] );
            },
            $this->lines
        );
        sort( $keys );
        return md5( (string) wp_json_encode( [ $keys, $this->currency ] ) );
    }
}
