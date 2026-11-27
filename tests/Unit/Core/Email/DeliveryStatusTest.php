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
        self::prop( 'save', fn( int $row, array $delivery ) => (bool) ( $this->saved[] = [ $row, $delivery ] ) );
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
        $this->assertSame( '31', DeliveryStatus::mark_of( [ 'List-Unsubscribe' => '<https://shop.test/?a=b:c>', 'X-YayMatik-Mark' => '31' ] ), 'as wp_mail_succeeded gets them' );
        $this->assertSame( '', DeliveryStatus::mark_of( "Content-Type: text/html\r\n" ), 'mail YayMatik didn’t send' );
    }

    public function test_a_later_report_is_saved_on_the_step_row_latest_wins(): void {
        DeliveryStatus::register();
        do_action( 'yaymatik_email_status', '12', 'delivered' );
        DeliveryStatus::record( '12', 'bounced', '550 No such user here' );

        $this->assertSame( [ 12, 12 ], array_column( $this->saved, 0 ) );
        $this->assertSame( 'bounced', $this->saved[1][1]['status'] );
        $this->assertSame( '550 No such user here', $this->saved[1][1]['reason'] );
        $this->assertArrayNotHasKey( 'reason', $this->saved[0][1] );
        $this->assertNotFalse( \DateTimeImmutable::createFromFormat( \DateTimeInterface::ATOM, $this->saved[0][1]['at'] ) );
    }

    public function test_a_report_while_the_step_runs_waits_for_its_finish(): void {
        DeliveryStatus::start( 7 );
        DeliveryStatus::record( 7, 'sent' );
        DeliveryStatus::record( 7, 'delivered' );

        $this->assertSame( [], $this->saved );
        $this->assertSame( 'delivered', DeliveryStatus::finish( 7 )['status'] );
        $this->assertNull( DeliveryStatus::finish( 7 ), 'taken once' );
    }

    public function test_send_test_unmarked_mail_and_unknown_statuses_are_ignored(): void {
        DeliveryStatus::on_sent( [ 'headers' => DeliveryStatus::header( DeliveryStatus::TEST ) ] );
        DeliveryStatus::on_sent( [ 'headers' => '' ] );
        DeliveryStatus::on_failed( 'not an error' );
        DeliveryStatus::on_failed( new \WP_Error( 'wp_mail_failed', 'x', (object) [ 'headers' => [] ] ) );
        DeliveryStatus::on_sent( (object) [] );
        DeliveryStatus::record( '12', 'read' );
        DeliveryStatus::record( '0', 'sent' );
        DeliveryStatus::record( [ 12 ], 'sent' );

        $this->assertSame( [], $this->saved );
    }
}
