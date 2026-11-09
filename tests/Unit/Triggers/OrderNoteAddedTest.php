<?php
namespace YayMatik\Tests\Unit\Triggers;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeOrder;
use YayMatik\Workflow\Triggers\OrderNoteAdded;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** Order note added: its note type, "contains" and skip settings. */
final class OrderNoteAddedTest extends TestCase {

    /** @var OrderNoteAdded */
    private $trigger;

    protected function setUp(): void {
        $this->trigger                         = new OrderNoteAdded();
        FakeOrder::$found                      = [
            12 => new FakeOrder(
                [
                    'id'   => 12,
                    'type' => 'shop_order',
                ]
            ),
        ];
        $GLOBALS['yaymatik_test_comment_rows'] = [
            40 => self::note( 12, '<b>Order SHIPPED</b> today', true ),
            41 => self::note( 12, 'Packed', false ),
            42 => self::note( 99, 'Another order', false ),
        ];
    }

    protected function tearDown(): void {
        FakeOrder::$found = [];
        unset( $GLOBALS['yaymatik_test_comment_rows'], $GLOBALS['yaymatik_test_filters']['woocommerce_order_note_added'] );
    }

    private static function note( int $order_id, string $text, bool $customer ): object {
        return (object) [
            'comment_ID'      => '0',
            'comment_post_ID' => (string) $order_id,
            'comment_type'    => 'order_note',
            'comment_content' => $text,
            'meta'            => [ 'is_customer_note' => $customer ? '1' : '' ],
        ];
    }

    private function context( int $note_id, bool $by_run = false ): ?array {
        $GLOBALS['yaymatik_test_comment_rows'][ $note_id ]->comment_ID = (string) $note_id;
        return $this->trigger->context(
            [
                'order_id' => 12,
                'note_id'  => $note_id,
                'by_run'   => $by_run,
            ]
        );
    }

    private function config( array $config = [] ): array {
        return $this->trigger->sanitize( $config );
    }

    public function test_defaults_any_note_and_skip_on(): void {
        $this->assertSame(
            [
                'note_type'     => 'any',
                'contains'      => '',
                'skip_yaymatik' => true,
            ],
            $this->config()
        );
    }

    public function test_context_reads_the_note_without_tags(): void {
        $context = $this->context( 40 );

        $this->assertSame( 'customer', $context['note_type'] );
        $this->assertSame( 'Order SHIPPED today', $context['note'] );
        $this->assertSame( 'private', $this->context( 41 )['note_type'] );
    }

    public function test_a_gone_note_or_one_of_another_order_has_no_context(): void {
        $this->assertNull( $this->context( 42 ), 'another order' );
        $this->assertNull(
            $this->trigger->context(
                [
                    'order_id' => 12,
                    'note_id'  => 77,
                ]
            ),
            'gone'
        );
    }

    public function test_note_type(): void {
        $this->assertTrue( $this->trigger->matches( $this->context( 40 ), $this->config( [ 'note_type' => 'customer' ] ) ) );
        $this->assertFalse( $this->trigger->matches( $this->context( 41 ), $this->config( [ 'note_type' => 'customer' ] ) ) );
        $this->assertTrue( $this->trigger->matches( $this->context( 41 ), $this->config( [ 'note_type' => 'private' ] ) ) );
        $this->assertTrue( $this->trigger->matches( $this->context( 41 ), $this->config() ) );
    }

    public function test_contains_ignores_case_and_empty_means_any(): void {
        $this->assertTrue( $this->trigger->matches( $this->context( 40 ), $this->config( [ 'contains' => 'shipped' ] ) ) );
        $this->assertTrue( $this->trigger->matches( $this->context( 40 ), $this->config( [ 'contains' => ' order shipped ' ] ) ) );
        $this->assertFalse( $this->trigger->matches( $this->context( 41 ), $this->config( [ 'contains' => 'shipped' ] ) ) );
        $this->assertFalse( $this->trigger->matches( $this->context( 40 ), $this->config( [ 'contains' => 'b>Order' ] ) ), 'tags are not text' );
    }

    public function test_contains_reads_a_saved_less_than_sign(): void {
        $GLOBALS['yaymatik_test_comment_rows'][43] = self::note( 12, 'Stock qty < 5', false );
        $config                                    = $this->config();
        $config['contains']                        = 'qty &lt; 5';

        $this->assertTrue( $this->trigger->matches( $this->context( 43 ), $config ) );
    }

    public function test_skip_toggle_drops_notes_added_by_runs(): void {
        $by_run = $this->context( 41, true );

        $this->assertFalse( $this->trigger->matches( $by_run, $this->config() ) );
        $this->assertTrue( $this->trigger->matches( $by_run, $this->config( [ 'skip_yaymatik' => false ] ) ) );
        $this->assertTrue( $this->trigger->matches( $this->context( 41 ), $this->config() ), 'a note from the store' );
    }

    public function test_fires_for_own_runs_only_with_skip_off(): void {
        $this->assertFalse( $this->trigger->fires_for_own_runs( $this->config() ) );
        $this->assertTrue( $this->trigger->fires_for_own_runs( $this->config( [ 'skip_yaymatik' => false ] ) ) );
    }

    public function test_listens_with_the_order_and_note(): void {
        $emitted = [];
        $this->trigger->listen(
            function ( array $args ) use ( &$emitted ) {
                $emitted[] = $args;
            }
        );
        do_action( 'woocommerce_order_note_added', 40, FakeOrder::$found[12] );
        do_action( 'woocommerce_order_note_added', 41, null );

        $this->assertSame(
            [
                [
                    'order_id' => 12,
                    'note_id'  => 40,
                    'by_run'   => false,
                ],
            ],
            $emitted
        );
    }
}
