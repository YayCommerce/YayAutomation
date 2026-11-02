<?php
namespace YayMatik\Tests\Unit\DataItems;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Cart\SavedCart;
use YayMatik\Workflow\DataItems\CartData;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** Cart fields that come from the saved lines. */
final class CartDataTest extends TestCase {

    /**
     * @param string[] $names
     */
    private function lines( array $names ): array {
        return array_map( fn( string $name ) => [ 'name' => $name ], $names );
    }

    public function test_no_products(): void {
        $this->assertSame( '', CartData::names( [] ) );
    }

    public function test_one_product(): void {
        $this->assertSame( 'Mug', CartData::names( $this->lines( [ 'Mug' ] ) ) );
    }

    public function test_two_products(): void {
        $this->assertSame( 'Mug and Cap', CartData::names( $this->lines( [ 'Mug', 'Cap' ] ) ) );
    }

    public function test_more_products_name_the_first_two(): void {
        $this->assertSame( 'Mug, Cap and 1 more', CartData::names( $this->lines( [ 'Mug', 'Cap', 'Bag' ] ) ) );
        $this->assertSame( 'Mug, Cap and 3 more', CartData::names( $this->lines( [ 'Mug', 'Cap', 'Bag', 'Hat', 'Pin' ] ) ) );
    }

    public function test_entities_in_a_stored_name_are_decoded(): void {
        $this->assertSame( 'Mug & Cap', CartData::names( $this->lines( [ 'Mug &amp; Cap' ] ) ) );
    }

    public function test_item_count_sums_the_quantities(): void {
        $value = ( new CartData() )->fields()['item_count']['value'];

        $this->assertSame( 5.0, $value( new SavedCart( [ 'items' => '[{"quantity":2},{"quantity":3}]' ] ) ) );
    }

    public function test_bad_json_reads_as_no_lines(): void {
        $this->assertSame( [], ( new SavedCart( [ 'items' => 'nope' ] ) )->lines() );
    }
}
