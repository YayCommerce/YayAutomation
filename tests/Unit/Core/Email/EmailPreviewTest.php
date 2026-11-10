<?php
namespace YayMatik\Tests\Unit\Core\Email;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeMailer;
use YayMatik\Workflow\Core\Email\EmailPreview;

require_once dirname( __DIR__, 3 ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__, 3 ) . '/Support/fake-wc.php';

/** Preview and Send test build one email: the mailer gets what Preview shows, apart from the "[Test]" subject. */
final class EmailPreviewTest extends TestCase {

    protected function setUp(): void {
        FakeMailer::$current = new FakeMailer();
    }

    private function graph(): array {
        $node = fn( string $id, string $kind, string $block ) => [
            'id'       => $id,
            'kind'     => $kind,
            'position' => [
                'x' => 0,
                'y' => 0,
            ],
            'data'     => [
                'block'  => $block,
                'config' => [],
            ],
        ];
        return [
            'nodes' => [ $node( 't', 'trigger', 'order_created' ), $node( 'm', 'action', 'send_email' ) ],
            'edges' => [
                [
                    'id'     => 't-m',
                    'source' => 't',
                    'target' => 'm',
                ],
            ],
        ];
    }

    private const CONFIG = [
        'to'      => 'ann@example.test',
        'subject' => 'Tom & Jerry',
        'heading' => '',
        'message' => 'Tom & Jerry',
    ];

    // Both inline styles with `WC_Email::style_inline()`, which needs WooCommerce: check-orders compares them through real `wp_mail()`.
    public function test_preview_shows_the_email_send_test_sends(): void {
        $preview = EmailPreview::render( $this->graph(), 'm', self::CONFIG, 0 );
        $sent    = EmailPreview::send_test( $this->graph(), 'm', self::CONFIG, 'you@example.test', 0 );

        $this->assertIsArray( $preview );
        $this->assertIsArray( $sent );
        list( $to, $subject, $body ) = FakeMailer::$current->sent[0];
        $this->assertSame( 'you@example.test', $to );
        $this->assertSame( '[Test] ' . $preview['subject'], $subject );
        $this->assertSame( $body, $preview['html'], 'the same HTML, byte for byte' );
        $this->assertStringContainsString( 'Tom &amp; Jerry', $preview['html'] );
        $this->assertTrue( $preview['sample'] );
        $this->assertNull( $preview['order'] );
        $this->assertNull( $preview['sampleProducts'] );
        $this->assertNull( $sent['sampleProducts'] );
    }

    public function test_grid_kinds_come_from_the_merge_tags_the_email_uses(): void {
        $this->assertSame( [], EmailPreview::grid_kinds( self::CONFIG ) );
        $this->assertSame( [ 'cross_sells' ], EmailPreview::grid_kinds( [ 'message' => '<p>{{ order.cross_sell_products }}</p>' ] ) );
        $this->assertSame( [ 'cross_sells', 'upsells' ], EmailPreview::grid_kinds( [ 'message' => '{{ order.upsell_products }} {{ order.cross_sell_products }}' ] ) );
    }

    public function test_the_order_picked_is_the_newest_with_every_grid_the_email_shows(): void {
        // Order 9 has cross-sells only, 8 has both, 7 has both: newest first.
        $grids    = [
            9 => [ 'cross_sells' ],
            8 => [ 'cross_sells', 'upsells' ],
            7 => [ 'cross_sells', 'upsells' ],
        ];
        $has_grid = fn( int $id, string $kind ) => in_array( $kind, $grids[ $id ] ?? [], true );

        $this->assertSame( 9, EmailPreview::pick_order( [ 9, 8, 7 ], [ 'cross_sells' ], $has_grid ) );
        $this->assertSame( 8, EmailPreview::pick_order( [ 9, 8, 7 ], [ 'cross_sells', 'upsells' ], $has_grid ) );
        $this->assertSame( 0, EmailPreview::pick_order( [ 9 ], [ 'upsells' ], $has_grid ), 'none has it: 0, so Preview shows sample products' );
        $this->assertSame( 0, EmailPreview::pick_order( [], [ 'cross_sells' ], $has_grid ) );
    }
}
