<?php
namespace YayMatik\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Cart\CartCustomer;
use YayMatik\Workflow\Core\Cart\SavedCart;
use YayMatik\Workflow\Core\Data\EmailCustomer;
use YayMatik\Workflow\Extension\CartViewAdapter;
use YayMatik\Workflow\Extension\CustomerViewAdapter;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** `CustomerView` over each customer object core loads, and `CartView` over a saved cart. */
final class ViewAdapterTest extends TestCase {

    private static function cart(): SavedCart {
        return new SavedCart(
            [
                'id'         => 9,
                'user_id'    => 0,
                'email'      => 'cart@example.test',
                'first_name' => 'Cy',
                'currency'   => 'EUR',
                'total'      => '42.50',
                'items'      => '[{"product_id":3,"variation_id":0,"quantity":2,"line_total":42.5,"name":"Mug"}]',
            ]
        );
    }

    public function test_customer_view_of_each_source(): void {
        $views = [
            new CustomerViewAdapter(
                new \WC_Order(
                    [
                        'id'                 => 12,
                        'customer_id'        => 0,
                        'billing_email'      => 'guest@example.test',
                        'billing_first_name' => 'Gia',
                        'billing_last_name'  => 'Ng',
                    ]
                )
            ),
            new CustomerViewAdapter(
                new \WC_Customer(
                    [
                        'id'                 => 5,
                        'billing_email'      => 'bo@example.test',
                        'billing_first_name' => 'Bo',
                        'billing_last_name'  => 'Le',
                    ]
                )
            ),
            new CustomerViewAdapter( new CartCustomer( self::cart(), null ) ),
            new CustomerViewAdapter( new EmailCustomer( 'reviewer@example.test' ) ),
        ];

        $this->assertSame(
            [
                [ 'guest@example.test', 0, 'Gia', 'Ng', 12 ],
                [ 'bo@example.test', 5, 'Bo', 'Le', 0 ],
                [ 'cart@example.test', 0, 'Cy', '', 0 ],
                [ 'reviewer@example.test', 0, '', '', 0 ],
            ],
            array_map( fn( $view ) => [ $view->email(), $view->user_id(), $view->first_name(), $view->last_name(), $view->order_id() ], $views )
        );
    }

    public function test_cart_view(): void {
        $view = new CartViewAdapter( self::cart() );

        $this->assertSame( [ 9, 'cart@example.test', 0, 'EUR', 42.5 ], [ $view->id(), $view->email(), $view->user_id(), $view->currency(), $view->total() ] );
        $this->assertSame(
            [
                [
                    'product_id'   => 3,
                    'variation_id' => 0,
                    'quantity'     => 2,
                    'line_total'   => 42.5,
                ],
            ],
            $view->items()
        );
    }
}
