<?php
namespace YayMatik\Workflow\Core\Cart;

defined( 'ABSPATH' ) || exit;

/** What a restore adds to the current cart: missing lines, and the larger quantity where both hold a line. */
final class CartMerge {

    /**
     * @param array[] $saved     The carts row's lines.
     * @param array[] $current   The visitor's cart lines.
     * @param bool[]  $available `CartSnapshot::line_key()` => whether that product can be added now; a missing key is unavailable.
     * @return array{add: array[], kept: int, skipped: int} `add`: each `[ 'line' => saved line, 'quantity' => to add ]`;
     *               `kept`: lines the cart already holds enough of; `skipped`: unavailable lines.
     */
    public static function plan( array $saved, array $current, array $available ): array {
        $have = [];
        foreach ( $current as $line ) {
            $key          = CartSnapshot::line_key( $line );
            $have[ $key ] = ( $have[ $key ] ?? 0 ) + (int) $line['quantity'];
        }
        // A cart can hold one product twice (different item data): one line each here.
        $want = [];
        foreach ( $saved as $line ) {
            $key = CartSnapshot::line_key( $line );
            if ( isset( $want[ $key ] ) ) {
                $want[ $key ]['quantity'] += (int) $line['quantity'];
            } else {
                $want[ $key ] = [
                    'line'     => $line,
                    'quantity' => (int) $line['quantity'],
                ];
            }
        }

        $plan = [
            'add'     => [],
            'kept'    => 0,
            'skipped' => 0,
        ];
        foreach ( $want as $key => $item ) {
            if ( empty( $available[ $key ] ) ) {
                ++$plan['skipped'];
                continue;
            }
            $missing = $item['quantity'] - ( $have[ $key ] ?? 0 );
            if ( $missing <= 0 ) {
                ++$plan['kept'];
                continue;
            }
            $plan['add'][] = [
                'line'     => $item['line'],
                'quantity' => $missing,
            ];
        }
        return $plan;
    }
}
