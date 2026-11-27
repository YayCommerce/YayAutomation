<?php
namespace YayMatik\Tests\Unit\Core\Email;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Email\DeliveryStatus;

/** The mark in mail headers, and where a reported status goes: the running step's output, or its stored row. */
final class DeliveryStatusTest extends TestCase {

    /** @var array[] [ row, delivery ] saved to the store. */
    private $saved = [];

    /** @var callable */
    private $save;

    /** @var array */
    private $filters;

    protected function setUp(): void {
        $this->filters = $GLOBALS['yaymatik_test_filters'] ?? [];
        $this->save    = self::prop( 'save' );
        self::prop(
            'save',
            function ( int $row, array $delivery ): bool {
                $this->saved[] = [ $row, $delivery ];
                return true;
            }
        );
    }

    protected function tearDown(): void {
        $GLOBALS['yaymatik_test_filters'] = $this->filters;
        self::prop( 'save', $this->save );
        self::prop( 'running', [] );
    }

    /**
     * Reads, or with a value sets, a private static of DeliveryStatus.
     *
     * @param string $name
     * @param mixed  ...$value
     * @return mixed
     */
    private static function prop( string $name, ...$value ) {
        $prop = new \ReflectionProperty( DeliveryStatus::class, $name );
        $prop->setAccessible( true );
        if ( $value ) {
            $prop->setValue( null, $value[0] );
        }
        return $prop->getValue();
    }

    public function test_the_mark_is_read_from_string_or_array_headers_in_any_case(): void {
        $this->assertSame( "X-YayMatik-Mark: 12\r\n", DeliveryStatus::header( '12' ) );
        $this->assertSame( '12', DeliveryStatus::mark_of( "Content-Type: text/html\r\n" . DeliveryStatus::header( '12' ) ) );
        $this->assertSame( 'test', DeliveryStatus::mark_of( [ 'Content-Type: text/html', 'x-yaymatik-mark:  test ' ] ) );
        $this->assertSame(
            '31',
            DeliveryStatus::mark_of(
                [
                    'List-Unsubscribe' => '<https://shop.test/?a=b:c>',
                    'X-YayMatik-Mark'  => '31',
                ]
            ),
            'as wp_mail_succeeded gets them'
        );
        $this->assertSame( '', DeliveryStatus::mark_of( "Content-Type: text/html\r\n" ), 'mail YayMatik didn’t send' );
    }

    public function test_a_later_report_is_saved_on_the_step_row(): void {
        DeliveryStatus::register();
        do_action( 'yaymatik_email_status', '12', 'delivered' );
        DeliveryStatus::record( '12', 'bounced', '550 No such user here' );

        $this->assertSame( [ 12, 12 ], array_column( $this->saved, 0 ) );
        $this->assertSame( 'bounced', $this->saved[1][1]['status'] );
        $this->assertSame( '550 No such user here', $this->saved[1][1]['reason'] );
        $this->assertArrayNotHasKey( 'reason', $this->saved[0][1] );
        $this->assertNotFalse( \DateTimeImmutable::createFromFormat( \DateTimeInterface::ATOM, $this->saved[0][1]['at'] ) );
    }

    public function test_a_report_replaces_only_a_stage_further_back_and_failed_or_bounced_is_final(): void {
        $this->assertTrue( DeliveryStatus::replaces( 'sent', null ) );
        $this->assertTrue( DeliveryStatus::replaces( 'opened', [ 'status' => 'delivered' ] ) );
        $this->assertFalse( DeliveryStatus::replaces( 'delivered', [ 'status' => 'opened' ] ), 'a late webhook' );
        $this->assertTrue( DeliveryStatus::replaces( 'bounced', [ 'status' => 'delivered' ] ) );
        $this->assertFalse( DeliveryStatus::replaces( 'opened', [ 'status' => 'bounced' ] ) );
        $this->assertFalse( DeliveryStatus::replaces( 'bounced', [ 'status' => 'failed' ] ) );

        DeliveryStatus::start( 8 );
        DeliveryStatus::record( 8, 'opened' );
        DeliveryStatus::record( 8, 'delivered' );
        $this->assertSame( 'opened', DeliveryStatus::finish( 8 )['status'], 'held while the step runs too' );
    }

    public function test_a_report_while_the_step_runs_waits_for_its_finish(): void {
        DeliveryStatus::start( 7 );
        DeliveryStatus::record( 7, 'sent' );
        DeliveryStatus::record( 7, 'delivered' );

        DeliveryStatus::on_sent( [ 'headers' => DeliveryStatus::header( '7' ) ] );

        $this->assertSame( [], $this->saved );
        $this->assertSame( 'delivered', DeliveryStatus::finish( 7 )['status'], 'core’s sent never replaces a mail plugin’s report' );
        $this->assertNull( DeliveryStatus::finish( 7 ), 'taken once' );
    }

    public function test_send_test_unmarked_mail_a_resend_and_unknown_statuses_are_ignored(): void {
        DeliveryStatus::on_sent( [ 'headers' => DeliveryStatus::header( DeliveryStatus::TEST ) ] );
        DeliveryStatus::on_sent( [ 'headers' => '' ] );
        DeliveryStatus::on_sent( [ 'headers' => DeliveryStatus::header( '12' ) ] );
        DeliveryStatus::on_failed( 'not an error' );
        DeliveryStatus::on_failed( new \WP_Error( 'wp_mail_failed', 'x', (object) [ 'headers' => [] ] ) );
        DeliveryStatus::on_sent( (object) [] );
        DeliveryStatus::record( '12', 'read' );
        DeliveryStatus::record( '0', 'sent' );
        DeliveryStatus::record( [ 12 ], 'sent' );

        $this->assertSame( [], $this->saved );
    }
}
