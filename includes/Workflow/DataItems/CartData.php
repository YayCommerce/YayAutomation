<?php
namespace YayMatik\Workflow\DataItems;

use YayMatik\Workflow\Core\Cart\SavedCart;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Email\CartItems;
use YayMatik\Workflow\Core\Email\ProductGrid;

defined( 'ABSPATH' ) || exit;

/** A saved cart (`SavedCart`). */
final class CartData extends DataItem {

    public const ID = 'cart';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Cart', 'yaymatik-automation-for-woocommerce' );
    }

    public function description(): string {
        return __( 'The cart that started this workflow: its shopper, products and total.', 'yaymatik-automation-for-woocommerce' );
    }

    public function icon(): string {
        return 'shopping-cart';
    }

    protected function define(): array {
        return [
            'email'          => [
                'label' => __( 'Email', 'yaymatik-automation-for-woocommerce' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( SavedCart $cart ) => $cart->email(),
            ],
            'first_name'     => [
                'label' => __( 'First name', 'yaymatik-automation-for-woocommerce' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( SavedCart $cart ) => $cart->first_name(),
            ],
            'total'          => [
                'label'      => __( 'Cart total', 'yaymatik-automation-for-woocommerce' ),
                'type'       => self::TYPE_NUMBER,
                'value_type' => Type::money(),
                'value'      => fn( SavedCart $cart ) => $cart->total(),
                'display'    => fn( SavedCart $cart, bool $html ) => $cart->price( $cart->total(), $html ),
            ],
            'item_count'     => [
                'label' => __( 'Number of products', 'yaymatik-automation-for-woocommerce' ),
                'type'  => self::TYPE_NUMBER,
                'value' => fn( SavedCart $cart ) => (float) array_sum( array_column( $cart->lines(), 'quantity' ) ),
            ],
            'product_names'  => [
                'label' => __( 'Product names', 'yaymatik-automation-for-woocommerce' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( SavedCart $cart ) => self::names( $cart->lines() ),
            ],
            'last_activity'  => [
                'label'     => __( 'Last activity', 'yaymatik-automation-for-woocommerce' ),
                'type'      => self::TYPE_DATE,
                'value'     => fn( SavedCart $cart ) => wp_date( 'Y-m-d', strtotime( $cart->last_activity() . ' UTC' ) ),
                'timestamp' => fn( SavedCart $cart ) => (int) strtotime( $cart->last_activity() . ' UTC' ),
                'display'   => fn( SavedCart $cart, bool $html ) => $html ? esc_html( self::activity_text( $cart ) ) : self::activity_text( $cart ),
            ],
            'restore_url'    => [
                'label'       => __( 'Restore link', 'yaymatik-automation-for-woocommerce' ),
                'type'        => self::TYPE_STRING,
                'value'       => fn( SavedCart $cart ) => $cart->restore_url(),
                'conditional' => false,
            ],
            'items'          => [
                'label'       => __( 'Cart items', 'yaymatik-automation-for-woocommerce' ),
                'type'        => self::TYPE_STRING,
                'value'       => fn( SavedCart $cart ) => CartItems::render( $cart, false ),
                'display'     => [ CartItems::class, 'render' ],
                'conditional' => false,
                'block'       => true,
            ],
            'restore_button' => [
                'label'       => __( 'Restore my cart button', 'yaymatik-automation-for-woocommerce' ),
                'type'        => self::TYPE_STRING,
                'value'       => fn( SavedCart $cart ) => CartItems::button( $cart, false ),
                'display'     => [ CartItems::class, 'button' ],
                'conditional' => false,
                'block'       => true,
            ],
        ];
    }

    /**
     * `A`, `A and B`, or `A, B and 3 more`.
     *
     * @param array[] $lines
     */
    public static function names( array $lines ): string {
        // WordPress may store titles with entities; merge tags escape on output, and subjects are plain text.
        $names = array_map( fn( $name ) => html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ), array_column( $lines, 'name' ) );
        $count = count( $names );
        if ( $count < 2 ) {
            return $names[0] ?? '';
        }
        if ( 2 === $count ) {
            /* translators: 1: a product name, 2: another product name */
            return sprintf( __( '%1$s and %2$s', 'yaymatik-automation-for-woocommerce' ), $names[0], $names[1] );
        }
        $more = $count - 2;
        /* translators: 1: a product name, 2: another product name, 3: how many more products */
        return sprintf( _n( '%1$s, %2$s and %3$d more', '%1$s, %2$s and %3$d more', $more, 'yaymatik-automation-for-woocommerce' ), $names[0], $names[1], $more );
    }

    /** Up to 3 of the store's newest products (made-up names when it has none), with a link that goes nowhere. */
    public function sample(): array {
        // SampleValues reads this once per merge tag.
        static $sample = null;
        if ( null !== $sample ) {
            return $sample;
        }
        $products = array_slice( ProductGrid::newest(), 0, 3 );
        $lines    = array_map(
            fn( \WC_Product $product ) => [
                'product_id' => $product->get_id(),
                'name'       => $product->get_name(),
                'quantity'   => 1,
                'line_total' => (float) $product->get_price(),
            ],
            $products
        );
        if ( ! $lines ) {
            $lines = [
                [
                    'name'       => __( 'Sample product', 'yaymatik-automation-for-woocommerce' ),
                    'quantity'   => 1,
                    'line_total' => 29.0,
                ],
                [
                    'name'       => __( 'Another sample product', 'yaymatik-automation-for-woocommerce' ),
                    'quantity'   => 2,
                    'line_total' => 40.0,
                ],
            ];
        }
        $now    = time();
        $total  = (float) array_sum( array_column( $lines, 'line_total' ) );
        $cart   = ( new SavedCart(
            [
                'id'               => 0,
                'items'            => (string) wp_json_encode( $lines ),
                'total'            => $total,
                'currency'         => get_woocommerce_currency(),
                'last_activity_at' => gmdate( 'Y-m-d H:i:s', $now ),
                'created_at'       => gmdate( 'Y-m-d H:i:s', $now ),
            ]
        ) )->inert();
        $block  = fn( callable $render ) => [
            'value' => $render( $cart, false ),
            'text'  => $render( $cart, false ),
            'html'  => $render( $cart, true ),
        ];
        $sample = [
            'email'          => 'jane.doe@example.com',
            'first_name'     => 'Jane',
            'total'          => [
                'value' => $total,
                'text'  => $cart->price( $total, false ),
                'html'  => $cart->price( $total, true ),
            ],
            'item_count'     => (float) array_sum( array_column( $lines, 'quantity' ) ),
            'product_names'  => self::names( $lines ),
            'last_activity'  => [
                'value' => wp_date( 'Y-m-d', $now ),
                'text'  => self::activity_text( $cart ),
            ],
            'restore_url'    => '#',
            'items'          => $block( [ CartItems::class, 'render' ] ),
            'restore_button' => $block( [ CartItems::class, 'button' ] ),
        ];
        return $sample;
    }

    /**
     * Date and time in the store's timezone.
     *
     * @param SavedCart $cart
     */
    private static function activity_text( SavedCart $cart ): string {
        $format = get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' );
        return (string) wp_date( $format, strtotime( $cart->last_activity() . ' UTC' ) );
    }
}
