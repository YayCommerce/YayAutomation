<?php
namespace YayMatik\Tests\Unit\Ecosystem;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/dev/ecosystem/yaymagic/YayMagic_YayMatik_Fake_Adapter.php';

/** YayMagic's fake adapter: a design saved in its editor is the one the email sends, and order blocks need an order. */
final class YayMagicEditorTest extends TestCase {

    /** @var \YayMagic_YayMatik_Fake_Adapter */
    private $adapter;

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_options'] = [];
        $this->adapter                    = new \YayMagic_YayMatik_Fake_Adapter();
    }

    /**
     * @param int $design
     */
    private function build( int $design ): array {
        return $this->adapter->build_email(
            [
                'subject'   => 'Hi {{ customer.first_name }}',
                'design_id' => (string) $design,
            ],
            null
        );
    }

    public function test_build_email_sends_a_sample_design_with_its_merge_tags_for_yaymatik_to_fill(): void {
        $email = $this->build( 1 );

        $this->assertSame( 'Hi {{ customer.first_name }}', $email['subject'] );
        $this->assertSame( \YayMagic_YayMatik_Fake_Adapter::SAMPLES[1]['body'], $email['html'] );
        $this->assertTrue( $email['wrap'] );
    }

    public function test_a_design_saved_in_the_editor_is_the_one_build_email_sends(): void {
        $id = $this->adapter->save_design( 0, 'Order thanks', '<p>Order {{ order.number }}</p>' );

        $this->assertSame( 4, $id );
        $this->assertSame( '<p>Order {{ order.number }}</p>', $this->build( 4 )['html'] );
        $this->assertSame( 'Order thanks', $this->adapter->design_options()[4] );

        $this->assertSame( 4, $this->adapter->save_design( 4, 'Order thanks', '<p>Thanks {{ customer.first_name }}</p>' ) );
        $this->assertSame( 1, $this->adapter->save_design( 1, 'Welcome 2', '<p>Welcome</p>' ) );
        $this->assertSame( '<p>Thanks {{ customer.first_name }}</p>', $this->build( 4 )['html'] );
        $this->assertSame( '<p>Welcome</p>', $this->build( 1 )['html'] );
        $this->assertSame( [ 1, 2, 3, 4 ], array_keys( $this->adapter->design_options() ) );
    }

    public function test_build_email_fails_on_a_design_that_is_gone(): void {
        $this->expectException( \RuntimeException::class );

        $this->build( 99 );
    }

    public function test_validate_refuses_order_blocks_on_a_trigger_without_an_order(): void {
        $id     = $this->adapter->save_design( 0, 'Order thanks', '<p>Order {{order.number}}</p>' );
        $config = [ 'design_id' => (string) $id ];

        $this->assertSame( [ 'design_id' ], array_keys( $this->adapter->validate( $config, [ 'customer', 'site' ] ) ) );
        $this->assertSame( [], $this->adapter->validate( $config, [ 'order', 'customer', 'site' ] ) );
        $this->assertSame( [], $this->adapter->validate( [ 'design_id' => '1' ], [ 'customer', 'site' ] ) );
    }

    public function test_editor_url_opens_the_step_s_design_on_this_site(): void {
        $this->assertSame( 'https://shop.test/wp-admin/admin.php?page=yaymagic-editor&design=2', $this->adapter->editor_url( [ 'value' => '2' ] ) );
    }
}
