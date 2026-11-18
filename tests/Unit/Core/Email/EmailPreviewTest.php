<?php
namespace YayMatik\Tests\Unit\Core\Email;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeMailer;
use YayMatik\Workflow\Core\Email\EmailPreview;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Extension\Extensions;

require_once dirname( __DIR__, 3 ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__, 3 ) . '/Support/fake-wc.php';

/** Preview and Send test build one email: the mailer gets what Preview shows, apart from the "[Test]" subject. */
final class EmailPreviewTest extends TestCase {

    protected function setUp(): void {
        FakeMailer::$current = new FakeMailer();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_users'] );
    }

    /**
     * @param string $trigger
     * @param string $action
     */
    private function graph( string $trigger = 'order_created', string $action = 'send_email' ): array {
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
            'nodes' => [ $node( 't', 'trigger', $trigger ), $node( 'm', 'action', $action ) ],
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
        $this->assertSame( [], $preview['sampleProducts'] );
        $this->assertSame( [], $sent['sampleProducts'] );
    }

    public function test_another_plugins_email_step_previews_and_sends_its_own_document(): void {
        $declare = function ( $registrar ) {
            $registrar->module(
                [
                    'id'    => 'acme',
                    'label' => 'Acme',
                ]
            )->action(
                'acme_mail',
                [
                    'label'       => 'Mail',
                    'description' => 'Sends.',
                    'email'       => [
                        'recipient' => 'customer',
                        'build'     => fn( array $config, $run ) => [
                            'subject' => 'Hi {{ customer.first_name }}',
                            'html'    => '<html><head><style>p{color:red}</style></head><body><p>' . ( $run->is_preview() ? 'preview' : 'live' ) . '</p></body></html>',
                        ],
                    ],
                ]
            );
        };
        $steps   = new \ReflectionProperty( StepRegistry::class, 'steps' );
        $steps->setAccessible( true );
        $before = StepRegistry::all();
        $steps->setValue( null, $before + [ 'acme_mail' => Extensions::collect( [], $declare )[0]->actions()[0] ] );
        try {
            $preview = EmailPreview::render( $this->graph( 'order_created', 'acme_mail' ), 'm', [], 0 );
            $sent    = EmailPreview::send_test( $this->graph( 'order_created', 'acme_mail' ), 'm', [], 'you@example.test', 0 );
        } finally {
            $steps->setValue( null, $before );
        }

        $this->assertIsArray( $preview );
        $this->assertIsArray( $sent );
        $this->assertTrue( $preview['sample'] );
        $this->assertStringStartsWith( '<html><head><style>p{color:red}</style></head><body><p>preview</p><p style=', $preview['html'], 'as built, footer added, no styles inlined' );
        $this->assertStringContainsString( '<a href="#">Unsubscribe</a>', $preview['html'] );
        $this->assertSame( $preview['html'], FakeMailer::$current->sent[0][2], 'Send test sends what Preview shows' );
        $this->assertSame( '[Test] ' . $preview['subject'], FakeMailer::$current->sent[0][1] );
    }

    public function test_a_customer_only_trigger_previews_the_newest_customer_account(): void {
        $GLOBALS['yaymatik_test_users'] = [
            3 => (object) [
                'ID'              => 3,
                'roles'           => [ 'customer' ],
                'user_registered' => '2026-01-02 10:00:00',
                'display_name'    => 'Old Customer',
            ],
            8 => (object) [
                'ID'              => 8,
                'roles'           => [ 'customer' ],
                'user_registered' => '2026-03-04 10:00:00',
                'display_name'    => 'Ann Lee',
            ],
            9 => (object) [
                'ID'              => 9,
                'roles'           => [ 'administrator' ],
                'user_registered' => '2026-05-06 10:00:00',
                'display_name'    => 'Admin',
            ],
        ];

        $preview = EmailPreview::render( $this->graph( 'customer_account_created' ), 'm', self::CONFIG );
        unset( $GLOBALS['yaymatik_test_users'] );
        $none = EmailPreview::render( $this->graph( 'customer_account_created' ), 'm', self::CONFIG );

        $this->assertFalse( $preview['sample'] );
        $this->assertSame( 'Ann Lee', $preview['customer'] );
        $this->assertNull( $preview['order'] );
        $this->assertTrue( $none['sample'], 'no customer account: made-up data' );
        $this->assertNull( $none['customer'] );
        $GLOBALS['yaymatik_test_users'] = [
            8 => (object) [
                'ID'              => 8,
                'roles'           => [ 'customer' ],
                'user_registered' => '2026-03-04 10:00:00',
                'display_name'    => 'Ann Lee',
            ],
        ];
        $review                         = EmailPreview::render( $this->graph( 'review_posted' ), 'm', self::CONFIG );
        $this->assertTrue( $review['sample'], 'a review trigger also has the review: made-up data, as before' );
    }

    public function test_customers_made_in_the_same_second_preview_the_higher_id(): void {
        // Listed lower id first: without the tie-break, the stand-in keeps that order.
        $GLOBALS['yaymatik_test_users'] = [
            4 => (object) [
                'ID'              => 4,
                'roles'           => [ 'customer' ],
                'user_registered' => '2026-03-04 10:00:00',
                'display_name'    => 'First',
            ],
            6 => (object) [
                'ID'              => 6,
                'roles'           => [ 'customer' ],
                'user_registered' => '2026-03-04 10:00:00',
                'display_name'    => 'Second',
            ],
        ];

        $this->assertSame( 'Second', EmailPreview::render( $this->graph( 'customer_account_created' ), 'm', self::CONFIG )['customer'] );
    }

    public function test_grid_kinds_come_from_the_merge_tags_the_email_uses(): void {
        $needs  = new \ReflectionProperty( EmailPreview::class, 'sample_needs' );
        $before = $needs->getValue();
        $needs->setValue( null, [] );
        EmailPreview::add_sample_need( 'fresh', 'order.fresh_products', fn() => true );
        EmailPreview::add_sample_need( 'gifts', 'order.gift_products', fn() => true );

        $this->assertSame( [], EmailPreview::grid_kinds( self::CONFIG ) );
        $this->assertSame( [ 'gifts' ], EmailPreview::grid_kinds( [ 'message' => '<p>{{ order.gift_products }}</p>' ] ) );
        $this->assertSame( [ 'fresh', 'gifts' ], EmailPreview::grid_kinds( [ 'message' => '{{ order.gift_products }} {{ order.fresh_products }}' ] ) );
        $needs->setValue( null, $before );
    }

    public function test_the_order_picked_has_the_most_grids_the_newest_among_equals(): void {
        // Newest first: 9 has cross-sells only, 8 and 7 have both.
        $present = [
            9 => [ 'cross_sells' ],
            8 => [ 'cross_sells', 'upsells' ],
            7 => [ 'cross_sells', 'upsells' ],
        ];
        $this->assertSame( 8, EmailPreview::pick_order( $present ) );
        $this->assertSame(
            9,
            EmailPreview::pick_order(
                [
                    9 => [ 'cross_sells' ],
                    8 => [ 'upsells' ],
                ]
            ),
            'equals: the newest'
        );
        $this->assertSame(
            8,
            EmailPreview::pick_order(
                [
                    9 => [],
                    8 => [ 'cross_sells' ],
                ]
            ),
            'a partial match beats none'
        );
        $this->assertSame(
            9,
            EmailPreview::pick_order(
                [
                    9 => [],
                    8 => [],
                ]
            ),
            'none has a grid: the newest, filled with sample products'
        );
        $this->assertSame( 0, EmailPreview::pick_order( [] ) );
    }
}
