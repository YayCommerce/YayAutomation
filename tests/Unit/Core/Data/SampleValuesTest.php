<?php
namespace YayMatik\Tests\Unit\Core\Data;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\DataItems\CustomerData;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Data\SampleValues;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Template;

require_once dirname( __DIR__, 3 ) . '/Support/woocommerce-stubs.php';

/** Made-up values for previews: the items' samples, relations followed, the rest from a Scope. */
final class SampleValuesTest extends TestCase {

    private function values( array $items = [ 'order', 'customer' ], array $steps = [] ): SampleValues {
        return new SampleValues( new Scope( [ 'steps' => $steps ] ), array_intersect_key( DataRegistry::items(), array_flip( $items ) ) );
    }

    public function test_fields_and_relations_read_the_samples(): void {
        $values = $this->values();

        $this->assertSame( 'Jane', $values->get( 'customer.first_name' ) );
        $this->assertSame( 'Jane', $values->get( 'order.customer.first_name' ), 'through the relation' );
        $this->assertSame( 'Jane', $values->get( 'trigger.customer.first_name' ) );
        $this->assertSame( '1001', $values->text( 'order.number', false ) );
        $this->assertTrue( $values->get( 'customer.is_first_order' ) );
        $this->assertSame( 'Yes', $values->text( 'customer.is_first_order', false ) );
    }

    public function test_display_texts_and_html_blocks(): void {
        $values = $this->values();

        $this->assertSame( 54.0, $values->get( 'order.total' ) );
        $this->assertSame( '$54.00', $values->text( 'order.total', false ) );
        $this->assertSame( '<span class="amount">&#36;54.00</span>', $values->text( 'order.total', true ) );
        $this->assertStringStartsWith( '<table class="td"', $values->text( 'order.items', true ) );
        $this->assertStringContainsString( 'Sample product ×1 $29.00', $values->text( 'order.items', false ) );
        $this->assertSame( 'Processing', $values->text( 'order.status', false ) );
    }

    public function test_plain_samples_are_escaped_in_html(): void {
        $values = $this->values( [ 'customer' ] );
        $this->assertSame( 'jane.doe@example.com', $values->text( 'customer.email', true ) );
    }

    public function test_items_the_trigger_does_not_supply_and_unknown_paths_read_empty(): void {
        $values = $this->values( [ 'customer' ] );

        $this->assertNull( $values->get( 'order.number' ) );
        $this->assertSame( '', $values->text( 'order.number', true ) );
        $this->assertSame( '', $values->text( 'customer.nope', false ) );
        $this->assertSame( '', $values->text( 'order.customer', false ), 'a relation has no text' );
    }

    public function test_step_outputs_and_the_site_come_from_the_scope(): void {
        $values = $this->values( [ 'order' ], [ 'coupon' => [ 'code' => 'YMSAMPLE' ] ] );

        $this->assertSame( 'YMSAMPLE', $values->text( 'steps.coupon.code', false ) );
        $this->assertSame( 'Hi Jane, use YMSAMPLE', ( new Template() )->render( 'Hi {{ order.customer.first_name }}, use {{ steps.coupon.code }}', $values ) );
    }

    public function test_every_customer_field_a_tag_can_read_has_a_sample(): void {
        $fields = array_filter( ( new CustomerData() )->fields(), fn( array $field ) => $field['merge_tag'] );
        $this->assertSame( array_keys( $fields ), array_keys( ( new CustomerData() )->sample() ) );
    }

    public function test_the_new_order_fields_read_their_samples(): void {
        $values = $this->values();
        $this->assertSame( [ '1', '2' ], $values->get( 'order.product_ids' ) );
        $this->assertSame( 'Sample product, Another sample product', $values->text( 'order.product_ids', false ) );
        $this->assertFalse( $values->get( 'order.is_guest' ) );
        $this->assertSame( 54.0, $values->get( 'customer.total_spent' ) );
    }
}
