<?php
namespace YayAutomation\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use YayAutomation\Workflow\Data\Variables;

/** Pins today's merge-tag parsing, so the template module that replaces it (F1.3) keeps old tags working. */
final class VariablesTest extends TestCase {

    public function test_keys_finds_tags_in_order_without_duplicates(): void {
        $this->assertSame(
            [ 'order.number', 'customer.email' ],
            Variables::keys( 'Order {{ order.number }} for {{customer.email}} ({{ order.number }})' )
        );
    }

    public function test_keys_ignores_text_that_is_not_a_tag(): void {
        $this->assertSame( [], Variables::keys( 'No tags, {{ order }} or {{ Order.Total }} here' ) );
    }
}
