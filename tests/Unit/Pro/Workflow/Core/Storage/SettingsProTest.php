<?php
namespace YayMatik\Tests\Unit\Pro\Workflow\Core\Storage;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Storage\Settings;

/** Settings YayMatik Pro saves: abandon minutes and the wider retention ranges. */
final class SettingsProTest extends TestCase {

    protected function setUp(): void {
        $this->forget_built();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_options'] );
        $this->forget_built();
    }

    public function test_update_saves_valid_changes_and_keeps_the_rest(): void {
        $result = Settings::update(
            [
                'abandon_minutes'  => 30,
                'cart_tracking'    => true,
                'unsubscribe_text' => " <i>Bye</i>\nSee you ",
                'old_key'          => 'x',
            ]
        );

        $this->assertSame( 30, $result['abandon_minutes'] );
        $this->assertTrue( $result['cart_tracking'] );
        $this->assertSame( "Bye\nSee you", $result['unsubscribe_text'] );
        $this->assertSame( 90, $result['run_retention_days'] );
        $this->assertSame( $result, Settings::all() );
        $this->assertArrayNotHasKey( 'old_key', $GLOBALS['yaymatik_test_options']['yaymatik_settings'] );
    }

    public function test_update_accepts_the_range_ends(): void {
        $result = Settings::update(
            [
                'abandon_minutes'     => 1440,
                'cart_retention_days' => 7,
                'run_retention_days'  => 730,
            ]
        );

        $this->assertSame( 1440, $result['abandon_minutes'] );
        $this->assertSame( 7, $result['cart_retention_days'] );
        $this->assertSame( 730, $result['run_retention_days'] );
    }

    public function test_update_with_an_invalid_value_saves_nothing_and_names_each_field(): void {
        Settings::update( [ 'abandon_minutes' => 30 ] );

        $result = Settings::update(
            [
                'abandon_minutes'     => 5,
                'cart_retention_days' => 366,
                'run_retention_days'  => '90',
                'cart_tracking'       => 'yes',
                'capture_guest_email' => 1,
                'unsubscribe_text'    => 12,
            ]
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $data = $result->get_error_data();
        $this->assertSame( 400, $data['status'] );
        $this->assertSame(
            [ 'abandon_minutes', 'cart_retention_days', 'run_retention_days', 'cart_tracking', 'capture_guest_email', 'unsubscribe_text' ],
            array_column( $data['errors'], 'field' )
        );
        $this->assertSame( 'Enter a whole number from 15 to 1440.', $data['errors'][0]['message'] );
        $this->assertSame( 'Choose on or off.', $data['errors'][3]['message'] );
        $this->assertSame( 'Enter text.', $data['errors'][5]['message'] );
        $this->assertSame( 30, Settings::get( 'abandon_minutes' ) );
    }

    /**
     * @param mixed $value
     */
    private function store( $value ): void {
        $GLOBALS['yaymatik_test_options']['yaymatik_settings'] = $value;
        $this->forget_built();
    }

    private function forget_built(): void {
        $built = new \ReflectionProperty( Settings::class, 'all' );
        $built->setAccessible( true );
        $built->setValue( null, null );
    }}
