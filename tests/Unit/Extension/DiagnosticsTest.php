<?php
namespace YayMatik\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Extension\Diagnostics;

/** The diagnostics option: last 50 entries, one write per module and callback per 10 minutes. */
final class DiagnosticsTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_options'] = [];
    }

    private static function stored(): array {
        return $GLOBALS['yaymatik_test_options'][ Diagnostics::OPTION ] ?? [];
    }

    public function test_an_error_is_stored_with_module_callback_and_message(): void {
        Diagnostics::record( 'acme_one', 'listen', 'Boom.' );

        $entry = Diagnostics::recent( 24 )[0];
        $this->assertSame( [ 'acme_one', 'listen', 'Boom.', 1 ], [ $entry['module'], $entry['callback'], $entry['message'], $entry['count'] ] );
        $this->assertEqualsWithDelta( time(), $entry['time'], 5 );
    }

    public function test_a_repeat_within_ten_minutes_counts_without_writing(): void {
        Diagnostics::record( 'acme_two', 'refs', 'First.' );
        Diagnostics::record( 'acme_two', 'refs', 'Second.' );
        Diagnostics::record( 'acme_two', 'refs', 'Third.' );

        $this->assertSame( 1, self::stored()['acme_two|refs']['count'], 'repeats are not written' );
        $this->assertSame( 'First.', self::stored()['acme_two|refs']['message'] );
        $this->assertSame( 3, Diagnostics::recent( 24 )[0]['count'], 'but they are counted' );

        Diagnostics::record( 'acme_two', 'run', 'Other callback.' );
        $this->assertSame( 3, self::stored()['acme_two|refs']['count'], 'the next write flushes the count' );
    }

    public function test_after_ten_minutes_the_entry_is_written_again(): void {
        $GLOBALS['yaymatik_test_options'][ Diagnostics::OPTION ] = [
            'acme_three|listen' => [
                'module'   => 'acme_three',
                'callback' => 'listen',
                'message'  => 'Old.',
                'time'     => time() - 601,
                'count'    => 4,
            ],
        ];
        Diagnostics::record( 'acme_three', 'listen', 'New.' );

        $this->assertSame( 'New.', self::stored()['acme_three|listen']['message'] );
        $this->assertSame( 5, self::stored()['acme_three|listen']['count'] );
    }

    public function test_only_the_last_fifty_are_kept(): void {
        $old = [];
        for ( $i = 0; $i < 50; $i++ ) {
            $old[ "acme_old|cb{$i}" ] = [
                'module'   => 'acme_old',
                'callback' => "cb{$i}",
                'message'  => 'Old.',
                'time'     => time() - 1000 + $i,
                'count'    => 1,
            ];
        }
        $GLOBALS['yaymatik_test_options'][ Diagnostics::OPTION ] = $old;
        Diagnostics::record( 'acme_four', 'run', 'Newest.' );

        $this->assertCount( 50, self::stored() );
        $this->assertArrayNotHasKey( 'acme_old|cb0', self::stored(), 'the oldest goes' );
        $this->assertSame( 'Newest.', Diagnostics::recent( 24 )[0]['message'], 'newest first' );
    }

    public function test_recent_leaves_out_older_entries_and_malformed_ones(): void {
        $GLOBALS['yaymatik_test_options'][ Diagnostics::OPTION ] = [
            'acme_five|a' => [
                'module'   => 'acme_five',
                'callback' => 'a',
                'message'  => 'Yesterday.',
                'time'     => time() - 25 * 3600,
                'count'    => 1,
            ],
            'acme_five|b' => [
                'module'   => 'acme_five',
                'callback' => 'b',
                'message'  => 'Now.',
                'time'     => time(),
                'count'    => 1,
            ],
            'broken'      => 'not an entry',
        ];

        $this->assertSame( [ 'Now.' ], array_column( Diagnostics::recent( 24 ), 'message' ) );
    }
}
