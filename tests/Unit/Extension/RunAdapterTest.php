<?php
namespace YayMatik\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use YayMatik\Api\CartView;
use YayMatik\Workflow\Core\Cart\SavedCart;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\DataItems\CartData;
use YayMatik\Workflow\Extension\RunAdapter;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** What `Run` reads: facts, the cart as a view, a module's own data item. */
final class RunAdapterTest extends TestCase {

    public function test_facts_and_the_cart(): void {
        $cart = new SavedCart(
            [
                'id'       => 9,
                'user_id'  => 0,
                'email'    => 'cart@example.test',
                'currency' => 'EUR',
                'total'    => '10',
                'items'    => '[]',
            ]
        );
        $run  = new RunAdapter(
            new Scope(
                [
                    'trigger' => [
                        'cart'   => new Ref( 'cart', 9 ),
                        'points' => 42,
                    ],
                ],
                new RefResolver( [ 'cart' => fn() => $cart ] ),
                [ CartData::ID => new CartData() ]
            )
        );

        $this->assertSame( 42, $run->get( 'trigger.points' ) );
        $this->assertInstanceOf( CartView::class, $run->cart() );
        $this->assertSame( 'cart@example.test', $run->cart()->email() );
        $this->assertNull( $run->item( 'cart' ), 'core items have their own getters' );
        $this->assertNull( ( new RunAdapter( new Scope( [], null, [] ) ) )->cart() );
    }
}
