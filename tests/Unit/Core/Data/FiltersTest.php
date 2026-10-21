<?php
namespace YayMatik\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Data\Filters;

/** The filter registry: input types, extension, and inputs the date filter accepts. */
final class FiltersTest extends TestCase {

    public function test_the_six_filters_declare_their_input_types(): void {
        $filters = new Filters( fn() => '', fn() => '' );
        $this->assertSame( [ 'any' ], $filters->input( 'default' ) );
        $this->assertSame( [ 'string' ], $filters->input( 'upper' ) );
        $this->assertSame( [ 'string' ], $filters->input( 'lower' ) );
        $this->assertSame( [ 'list' ], $filters->input( 'join' ) );
        $this->assertSame( [ 'date', 'datetime' ], $filters->input( 'date' ) );
        $this->assertSame( [ 'number', 'money' ], $filters->input( 'money' ) );
        $this->assertNull( $filters->input( 'raw' ) );
    }

    public function test_there_is_no_raw_filter(): void {
        $this->assertFalse( ( new Filters() )->has( 'raw' ) );
    }

    public function test_added_filters_run(): void {
        $filters = new Filters( fn() => '', fn() => '' );
        $filters->add( 'twice', [ 'string' ], fn( $value ) => $value . $value );
        $this->assertSame( 'abab', $filters->apply( 'twice', 'ab', [] ) );
        $this->assertSame( 'ab', $filters->apply( 'missing', 'ab', [] ) );
    }

    public function test_default_replaces_null_blank_and_empty_values(): void {
        $filters = new Filters( fn() => '', fn() => '' );
        $this->assertSame( 'there', $filters->apply( 'default', null, [ 'there' ] ) );
        $this->assertSame( 'there', $filters->apply( 'default', '', [ 'there' ] ) );
        $this->assertSame( 'there', $filters->apply( 'default', '   ', [ 'there' ] ) );
        $this->assertSame( 'there', $filters->apply( 'default', [], [ 'there' ] ) );
        $this->assertSame( '', $filters->apply( 'default', null, [] ) );
        $this->assertSame( 0, $filters->apply( 'default', 0, [ 'there' ] ) );
        $this->assertSame( 'Ana', $filters->apply( 'default', 'Ana', [ 'there' ] ) );
    }

    public function test_upper_and_lower_handle_multibyte_text(): void {
        $filters = new Filters( fn() => '', fn() => '' );
        $this->assertSame( 'ĐƠN HÀNG', $filters->apply( 'upper', 'đơn hàng', [] ) );
        $this->assertSame( 'đơn hàng', $filters->apply( 'lower', 'ĐƠN HÀNG', [] ) );
        $this->assertSame( 42, $filters->apply( 'upper', 42, [] ) );
    }

    public function test_join_skips_blanks_and_takes_a_separator(): void {
        $filters = new Filters( fn() => '', fn() => '' );
        $this->assertSame( 'A, B', $filters->apply( 'join', [ 'A', '', null, 'B' ], [] ) );
        $this->assertSame( 'A / B', $filters->apply( 'join', [ 'A', 'B' ], [ ' / ' ] ) );
        $this->assertSame( 'A', $filters->apply( 'join', 'A', [] ) );
    }

    public function test_money_takes_numbers_and_money_values(): void {
        $filters = new Filters( fn() => '', fn( float $amount, string $currency ) => $currency . '|' . $amount );
        $this->assertSame( '|42.5', $filters->apply( 'money', 42.5, [] ) );
        $this->assertSame( '|42', $filters->apply( 'money', '42', [] ) );
        $this->assertSame(
            'EUR|10',
            $filters->apply(
                'money',
                [
                    'amount'   => 10,
                    'currency' => 'EUR',
                ],
                []
            )
        );
        $this->assertSame( 'n/a', $filters->apply( 'money', 'n/a', [] ) );
    }

    public function test_date_strings_keep_their_day_in_a_negative_offset_timezone(): void {
        $new_york = new \DateTimeZone( 'America/New_York' );
        $filters  = new Filters(
            fn( int $timestamp, string $format ) => ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $new_york )->format( $format ),
            fn() => '',
            $new_york
        );
        // Strings without an offset are read in the site's timezone.
        $this->assertSame( '2026-10-09', $filters->apply( 'date', '2026-10-09', [ 'Y-m-d' ] ) );
        $this->assertSame( '2026-10-09 15:30', $filters->apply( 'date', '2026-10-09 15:30:00', [ 'Y-m-d H:i' ] ) );
        // Timestamps, and strings with their own offset, are taken as they are: 02:00 UTC is the evening before in New York.
        $two_am_utc = ( new \DateTimeImmutable( '2026-10-09 02:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        $this->assertSame( '2026-10-08 22:00', $filters->apply( 'date', $two_am_utc, [ 'Y-m-d H:i' ] ) );
        $this->assertSame( '2026-10-08 22:00', $filters->apply( 'date', '2026-10-09T02:00:00+00:00', [ 'Y-m-d H:i' ] ) );
    }

    public function test_date_takes_timestamps_strings_and_date_objects(): void {
        $filters = new Filters( fn( int $timestamp, string $format ) => gmdate( $format, $timestamp ), fn() => '', new \DateTimeZone( 'UTC' ) );
        $this->assertSame( '2026-10-09', $filters->apply( 'date', 1791547200, [ 'Y-m-d' ] ) );
        $this->assertSame( '2026-10-09', $filters->apply( 'date', '1791547200', [ 'Y-m-d' ] ) );
        $this->assertSame( '2026-10-09', $filters->apply( 'date', '2026-10-09', [ 'Y-m-d' ] ) );
        $this->assertSame( '2026-10-09', $filters->apply( 'date', new \DateTimeImmutable( '2026-10-09', new \DateTimeZone( 'UTC' ) ), [ 'Y-m-d' ] ) );
        $this->assertNull( $filters->apply( 'date', null, [ 'Y-m-d' ] ) );
        $this->assertSame( 'soon', $filters->apply( 'date', 'soon', [ 'Y-m-d' ] ) );
    }
}
