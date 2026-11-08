<?php
namespace YayMatik\Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Actions\WaitUntil;
use YayMatik\Workflow\Core\Data\Scope;

/** Wait until: the next chosen hour on a chosen day, in the store's time zone. */
final class WaitUntilTest extends TestCase {

    /**
     * Unix time of a wall-clock time in a zone.
     *
     * @param string $local e.g. "2026-11-09 08:30".
     * @param string $zone
     */
    private static function local_time( string $local, string $zone ): int {
        return ( new \DateTimeImmutable( $local, new \DateTimeZone( $zone ) ) )->getTimestamp();
    }

    /**
     * The local wall-clock time of a Unix time.
     *
     * @param int    $time
     * @param string $zone
     */
    private static function local( int $time, string $zone ): string {
        return ( new \DateTimeImmutable( '@' . $time ) )->setTimezone( new \DateTimeZone( $zone ) )->format( 'D Y-m-d H:i T' );
    }

    /**
     * @dataProvider cases
     * @param string $now
     * @param int    $hour
     * @param int[]  $days
     * @param string $zone
     * @param string $expected
     */
    public function test_next_time( string $now, int $hour, array $days, string $zone, string $expected ): void {
        $next = WaitUntil::next_time( self::local_time( $now, $zone ), $hour, $days, new \DateTimeZone( $zone ) );
        $this->assertSame( $expected, self::local( $next, $zone ) );
    }

    public function cases(): array {
        // 2026-11-09 is a Monday.
        $hcm = 'Asia/Ho_Chi_Minh';
        $ny  = 'America/New_York';
        return [
            'later today'              => [ '2026-11-09 08:30', 9, [], $hcm, 'Mon 2026-11-09 09:00 +07' ],
            'passed today'             => [ '2026-11-09 09:30', 9, [], $hcm, 'Tue 2026-11-10 09:00 +07' ],
            'exactly now is passed'    => [ '2026-11-09 09:00', 9, [], $hcm, 'Tue 2026-11-10 09:00 +07' ],
            'a chosen weekday'         => [ '2026-11-09 08:00', 9, [ 3 ], $hcm, 'Wed 2026-11-11 09:00 +07' ],
            'weekdays only, on Friday' => [ '2026-11-13 10:00', 9, [ 1, 2, 3, 4, 5 ], $hcm, 'Mon 2026-11-16 09:00 +07' ],
            'week wrap, same day'      => [ '2026-11-09 10:00', 9, [ 1 ], $hcm, 'Mon 2026-11-16 09:00 +07' ],
            'hour 0'                   => [ '2026-11-09 23:59', 0, [], $hcm, 'Tue 2026-11-10 00:00 +07' ],
            'spring forward'           => [ '2026-03-07 12:00', 9, [], $ny, 'Sun 2026-03-08 09:00 EDT' ],
            'in the spring gap'        => [ '2026-03-07 12:00', 2, [ 7 ], $ny, 'Sun 2026-03-08 03:00 EDT' ],
            'fall back'                => [ '2026-10-31 12:00', 9, [], $ny, 'Sun 2026-11-01 09:00 EST' ],
            'the hour that repeats'    => [ '2026-10-31 12:00', 1, [ 7 ], $ny, 'Sun 2026-11-01 01:00 EDT' ],
            'a negative offset'        => [ '2026-11-09 20:00', 9, [ 2 ], 'America/Los_Angeles', 'Tue 2026-11-10 09:00 PST' ],
        ];
    }

    public function test_a_day_changing_in_utc_but_not_locally_counts_locally(): void {
        // 23:30 in Los Angeles is already Tuesday in UTC; Monday 23:30 local is passed 09:00, so Tuesday 09:00 local.
        $zone = 'America/Los_Angeles';
        $next = WaitUntil::next_time( self::local_time( '2026-11-09 23:30', $zone ), 9, [ 1 ], new \DateTimeZone( $zone ) );
        $this->assertSame( 'Mon 2026-11-16 09:00 PST', self::local( $next, $zone ) );
    }

    public function test_run_waits_and_outputs_the_store_time(): void {
        $step   = new WaitUntil();
        $result = $step->run( new Scope(), $step->sanitize( [ 'weekdays' => [ '1', '9' ] ] ) );
        $this->assertTrue( $result->is_wait() );
        $this->assertGreaterThan( time(), $result->until() );
        $this->assertSame( 'Mon 09:00', ( new \DateTimeImmutable( '@' . $result->until() ) )->setTimezone( wp_timezone() )->format( 'D H:i' ) );
        $this->assertSame( ( new \DateTimeImmutable( '@' . $result->until() ) )->setTimezone( wp_timezone() )->format( 'Y-m-d H:i' ), $result->outputs()['until'] );
    }

    public function test_the_lists(): void {
        $this->assertCount( 24, WaitUntil::hours() );
        $this->assertSame( '9', ( new WaitUntil() )->sanitize( [] )['hour'] );
        $this->assertSame( '0', ( new WaitUntil() )->sanitize( [ 'hour' => '0' ] )['hour'], 'midnight is a value, not blank' );
        $this->assertSame( [ '1', '2', '3', '4', '5', '6', '7' ], array_map( 'strval', array_keys( WaitUntil::weekdays() ) ) );
    }
}
