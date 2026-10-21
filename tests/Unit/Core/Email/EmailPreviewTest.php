<?php
namespace YayMatik\Tests\Unit\Email;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeMailer;
use YayMatik\Workflow\Email\EmailPreview;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__, 2 ) . '/Support/fake-wc.php';

/**
 * Preview and Send test build one email: what the mailer gets from Send test is what Preview shows,
 * apart from the "[Test]" subject. WooCommerce inlines its styles in the mailer's `send()` and
 * Preview with the same `WC_Email::style_inline()`; that part needs WooCommerce, so check-orders
 * compares the two through real `wp_mail()` too.
 */
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
    }
}
