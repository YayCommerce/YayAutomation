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
     * @param string $time
     * @param int[]  $days
     * @param string $zone
     * @param string $expected
     */
    public function test_next_time( string $now, string $time, array $days, string $zone, string $expected ): void {
        list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
        $next                  = WaitUntil::next_time( self::local_time( $now, $zone ), $hour, $minute, $days, new \DateTimeZone( $zone ) );
        $this->assertSame( $expected, self::local( $next, $zone ) );
    }

    public function cases(): array {
        // 2026-11-09 is a Monday.
        $hcm = 'Asia/Ho_Chi_Minh';
        $ny  = 'America/New_York';
        return [
            'later today'                    => [ '2026-11-09 08:30', '09:00', [], $hcm, 'Mon 2026-11-09 09:00 +07' ],
            'passed today'                   => [ '2026-11-09 09:30', '09:00', [], $hcm, 'Tue 2026-11-10 09:00 +07' ],
            'exactly now is passed'          => [ '2026-11-09 09:00', '09:00', [], $hcm, 'Tue 2026-11-10 09:00 +07' ],
            'minutes later today'            => [ '2026-11-09 09:00', '09:15', [], $hcm, 'Mon 2026-11-09 09:15 +07' ],
            'minutes passed today'           => [ '2026-11-09 09:20', '09:15', [], $hcm, 'Tue 2026-11-10 09:15 +07' ],
            'a chosen weekday'               => [ '2026-11-09 08:00', '09:00', [ 3 ], $hcm, 'Wed 2026-11-11 09:00 +07' ],
            'weekdays only, on Friday'       => [ '2026-11-13 10:00', '09:45', [ 1, 2, 3, 4, 5 ], $hcm, 'Mon 2026-11-16 09:45 +07' ],
            'week wrap, same day'            => [ '2026-11-09 10:00', '09:00', [ 1 ], $hcm, 'Mon 2026-11-16 09:00 +07' ],
            'midnight'                       => [ '2026-11-09 23:59', '00:00', [], $hcm, 'Tue 2026-11-10 00:00 +07' ],
            'the last time of the day'       => [ '2026-11-09 23:30', '23:45', [], $hcm, 'Mon 2026-11-09 23:45 +07' ],
            'spring forward'                 => [ '2026-03-07 12:00', '09:00', [], $ny, 'Sun 2026-03-08 09:00 EDT' ],
            'in the spring gap'              => [ '2026-03-07 12:00', '02:00', [ 7 ], $ny, 'Sun 2026-03-08 03:00 EDT' ],
            'minutes in the spring gap'      => [ '2026-03-07 12:00', '02:30', [ 7 ], $ny, 'Sun 2026-03-08 03:30 EDT' ],
            'fall back'                      => [ '2026-10-31 12:00', '09:00', [], $ny, 'Sun 2026-11-01 09:00 EST' ],
            'the hour that repeats'          => [ '2026-10-31 12:00', '01:00', [ 7 ], $ny, 'Sun 2026-11-01 01:00 EDT' ],
            'minutes in the repeated hour'   => [ '2026-10-31 12:00', '01:45', [ 7 ], $ny, 'Sun 2026-11-01 01:45 EDT' ],
            'past the first, before the end' => [ '2026-11-01 01:50 EDT', '01:15', [ 7 ], $ny, 'Sun 2026-11-08 01:15 EST' ],
            'a negative offset'              => [ '2026-11-09 20:00', '09:30', [ 2 ], 'America/Los_Angeles', 'Tue 2026-11-10 09:30 PST' ],
        ];
    }

    public function test_a_day_changing_in_utc_but_not_locally_counts_locally(): void {
        // 23:30 in Los Angeles is already Tuesday in UTC; Monday 23:30 local is passed 09:00, so Tuesday 09:00 local.
        $zone = 'America/Los_Angeles';
        $next = WaitUntil::next_time( self::local_time( '2026-11-09 23:30', $zone ), 9, 0, [ 1 ], new \DateTimeZone( $zone ) );
        $this->assertSame( 'Mon 2026-11-16 09:00 PST', self::local( $next, $zone ) );
    }

    public function test_run_waits_and_outputs_the_store_time(): void {
        $step   = new WaitUntil();
        $result = $step->run(
            new Scope(),
            $step->sanitize(
                [
                    'time'     => '09:15',
                    'weekdays' => [ '1', '9' ],
                ]
            )
        );
        $this->assertTrue( $result->is_wait() );
        $this->assertGreaterThan( time(), $result->until() );
        $this->assertSame( 'Mon 09:15', ( new \DateTimeImmutable( '@' . $result->until() ) )->setTimezone( wp_timezone() )->format( 'D H:i' ) );
        $this->assertSame( ( new \DateTimeImmutable( '@' . $result->until() ) )->setTimezone( wp_timezone() )->format( 'Y-m-d H:i' ), $result->outputs()['until'] );
    }

    public function test_the_lists(): void {
        $times = WaitUntil::times();
        $this->assertCount( 96, $times );
        $this->assertSame( [ '00:00', '00:15' ], array_slice( array_keys( $times ), 0, 2 ) );
        $this->assertSame( '23:45', array_key_last( $times ) );
        $this->assertSame( '9:15 am', $times['09:15'] );
        $this->assertSame( [ '1', '2', '3', '4', '5', '6', '7' ], array_map( 'strval', array_keys( WaitUntil::weekdays() ) ) );
    }

    /**
     * @dataProvider bad_times
     * @param array $config
     */
    public function test_a_time_off_the_list_is_an_issue( array $config ): void {
        $step  = new WaitUntil();
        $error = $step->validate( $step->sanitize( $config ) );
        $this->assertInstanceOf( \WP_Error::class, $error );
        $this->assertSame( 'Choose a time in 15-minute steps.', $error->get_error_message() );
        $this->assertSame( 'time', $error->get_error_data()['field'] );
    }

    public function bad_times(): array {
        return [
            'no time'                  => [ [] ],
            'the old hour setting'     => [ [ 'hour' => '9' ] ],
            'no leading zero'          => [ [ 'time' => '9:15' ] ],
            'off the 15-minute step'   => [ [ 'time' => '09:10' ] ],
            'past the day'             => [ [ 'time' => '24:00' ] ],
            'seconds'                  => [ [ 'time' => '09:15:00' ] ],
        ];
    }

    public function test_times_on_the_list_pass(): void {
        $step = new WaitUntil();
        foreach ( [ '00:00', '09:15', '23:45' ] as $time ) {
            $this->assertTrue( $step->validate( $step->sanitize( [ 'time' => $time ] ) ), $time );
        }
    }
}
