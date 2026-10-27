<?php
namespace YayMatik\Tests\Unit\Core\Cart;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeClock;
use YayMatik\Workflow\Core\Cart\CaptureLimits;

/** Email capture limits: 10 / 10 min per session, 20 / 15 min per IP, 500 / 15 min site-wide. */
final class CaptureLimitsTest extends TestCase {

    /** @var FakeClock */
    private $clock;

    /** @var CaptureLimits */
    private $limits;

    protected function setUp(): void {
        $this->clock  = new FakeClock();
        $this->limits = new CaptureLimits( 'secret', $this->clock );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_transients'] );
    }

    public function test_the_eleventh_capture_of_a_session_is_refused(): void {
        for ( $i = 0; $i < 10; $i++ ) {
            $this->assertTrue( $this->limits->allow( 't_a', '10.0.0.' . $i ) );
        }

        $this->assertFalse( $this->limits->allow( 't_a', '10.0.0.99' ) );
        $this->assertTrue( $this->limits->allow( 't_b', '10.0.0.99' ) );
    }

    public function test_the_session_window_resets_after_ten_minutes(): void {
        for ( $i = 0; $i < 10; $i++ ) {
            $this->limits->allow( 't_a', '10.0.0.' . $i );
        }
        $this->clock->now += 10 * 60 - 1;
        $this->assertFalse( $this->limits->allow( 't_a', '10.0.0.50' ) );

        $this->clock->now += 1;
        $this->assertTrue( $this->limits->allow( 't_a', '10.0.0.50' ) );
    }

    public function test_the_twenty_first_capture_from_an_ip_is_refused_until_fifteen_minutes_pass(): void {
        for ( $i = 0; $i < 20; $i++ ) {
            $this->assertTrue( $this->limits->allow( 't_' . $i, '10.0.0.1' ) );
        }

        $this->assertFalse( $this->limits->allow( 't_new', '10.0.0.1' ) );
        $this->assertTrue( $this->limits->allow( 't_new', '10.0.0.2' ) );
        $this->clock->now += 15 * 60;
        $this->assertTrue( $this->limits->allow( 't_new2', '10.0.0.1' ) );
    }

    public function test_the_site_wide_limit_is_500_in_fifteen_minutes(): void {
        for ( $i = 0; $i < 500; $i++ ) {
            $this->assertTrue( $this->limits->allow( 't_' . $i, '10.1.' . intdiv( $i, 250 ) . '.' . ( $i % 250 ) ) );
        }

        $this->assertFalse( $this->limits->allow( 't_new', '10.9.9.9' ) );
        $this->clock->now += 15 * 60;
        $this->assertTrue( $this->limits->allow( 't_new', '10.9.9.9' ) );
    }

    public function test_a_refused_capture_counts_nowhere(): void {
        for ( $i = 0; $i < 10; $i++ ) {
            $this->limits->allow( 't_a', '10.0.0.1' );
        }
        for ( $i = 0; $i < 5; $i++ ) {
            $this->assertFalse( $this->limits->allow( 't_a', '10.0.0.1' ) );
        }

        // 10 counted for the IP, not 15: ten more sessions still fit.
        for ( $i = 0; $i < 10; $i++ ) {
            $this->assertTrue( $this->limits->allow( 't_other' . $i, '10.0.0.1' ) );
        }
        $this->assertFalse( $this->limits->allow( 't_last', '10.0.0.1' ) );
    }

    public function test_no_plain_ip_or_session_in_the_keys(): void {
        $this->limits->allow( 't_secret_session', '203.0.113.7' );

        $keys = implode( ' ', array_keys( $GLOBALS['yaymatik_test_transients'] ) );
        $this->assertStringNotContainsString( '203.0.113.7', $keys );
        $this->assertStringNotContainsString( 't_secret_session', $keys );
    }
}
