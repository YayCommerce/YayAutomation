<?php
namespace YayMatik\Workflow\DataItems;

use YayMatik\Workflow\Core\Cart\SavedCart;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\Type;

defined( 'ABSPATH' ) || exit;

/** A saved cart (`SavedCart`). */
final class CartData extends DataItem {

    public const ID = 'cart';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Cart', 'yaymatik' );
    }

    public function description(): string {
        return __( 'The cart that started this workflow: its shopper, products and total.', 'yaymatik' );
    }

    public function icon(): string {
        return 'shopping-cart';
    }

    protected function define(): array {
        return [
            'email'         => [
                'label' => __( 'Email', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( SavedCart $cart ) => $cart->email(),
            ],
            'first_name'    => [
                'label' => __( 'First name', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( SavedCart $cart ) => $cart->first_name(),
            ],
            'total'         => [
                'label'      => __( 'Cart total', 'yaymatik' ),
                'type'       => self::TYPE_NUMBER,
                'value_type' => Type::money(),
                'value'      => fn( SavedCart $cart ) => $cart->total(),
                'display'    => fn( SavedCart $cart, bool $html ) => self::price( $cart, $cart->total(), $html ),
            ],
            'item_count'    => [
                'label' => __( 'Number of products', 'yaymatik' ),
                'type'  => self::TYPE_NUMBER,
                'value' => fn( SavedCart $cart ) => (float) array_sum( array_column( $cart->lines(), 'quantity' ) ),
            ],
            'product_names' => [
                'label' => __( 'Product names', 'yaymatik' ),
                'type'  => self::TYPE_STRING,
                'value' => fn( SavedCart $cart ) => self::names( $cart->lines() ),
            ],
            'last_activity' => [
                'label'   => __( 'Last activity', 'yaymatik' ),
                'type'    => self::TYPE_DATE,
                'value'   => fn( SavedCart $cart ) => wp_date( 'Y-m-d', strtotime( $cart->last_activity() . ' UTC' ) ),
                'display' => function ( SavedCart $cart, bool $html ) {
                    $format = get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' );
                    $text   = (string) wp_date( $format, strtotime( $cart->last_activity() . ' UTC' ) );
                    return $html ? esc_html( $text ) : $text;
                },
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
            return sprintf( __( '%1$s and %2$s', 'yaymatik' ), $names[0], $names[1] );
        }
        $more = $count - 2;
        /* translators: 1: a product name, 2: another product name, 3: how many more products */
        return sprintf( _n( '%1$s, %2$s and %3$d more', '%1$s, %2$s and %3$d more', $more, 'yaymatik' ), $names[0], $names[1], $more );
    }

    /**
     * An amount in the cart's currency: HTML from wc_price(), or the same as plain text.
     *
     * @param SavedCart $cart
     * @param float     $amount
     * @param bool      $html
     */
    public static function price( SavedCart $cart, float $amount, bool $html ): string {
        $price = wc_price( $amount, [ 'currency' => $cart->currency() ] );
        return $html ? $price : html_entity_decode( wp_strip_all_tags( $price ), ENT_QUOTES, 'UTF-8' );
    }
}
