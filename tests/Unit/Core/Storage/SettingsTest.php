<?php
namespace YayMatik\Tests\Unit\Core\Storage;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Storage\Settings;

/** Settings read from and saved to the option. */
final class SettingsTest extends TestCase {

    protected function setUp(): void {
        $this->forget_built();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_options'] );
        $this->forget_built();
    }

    public function test_settings_are_built_once_and_update_starts_from_the_stored_option(): void {
        Settings::all();
        $GLOBALS['yaymatik_test_options']['yaymatik_settings'] = [ 'abandon_minutes' => 30 ];

        $this->assertSame( 60, Settings::get( 'abandon_minutes' ) );
        Settings::update( [ 'cart_tracking' => true ] );
        $this->assertSame( 30, Settings::get( 'abandon_minutes' ) );
        $this->assertTrue( Settings::get( 'cart_tracking' ) );
    }

    public function test_nothing_stored_reads_the_defaults(): void {
        $this->assertSame(
            [
                'cart_tracking'       => false,
                'abandon_minutes'     => 60,
                'capture_guest_email' => true,
                'cart_retention_days' => 60,
                'run_retention_days'  => 90,
                'unsubscribe_text'    => '',
            ],
            Settings::all()
        );
        $this->assertSame( Settings::defaults(), Settings::all() );
    }

    public function test_stored_values_are_cast_clamped_and_unknown_keys_dropped(): void {
        $this->store(
            [
                'cart_tracking'       => '1',
                'capture_guest_email' => 0,
                'abandon_minutes'     => 5,
                'cart_retention_days' => '9999',
                'run_retention_days'  => 'many',
                'unsubscribe_text'    => [ 'not text' ],
                'old_key'             => 'x',
            ]
        );

        $all = Settings::all();

        $this->assertTrue( $all['cart_tracking'] );
        $this->assertFalse( $all['capture_guest_email'] );
        $this->assertSame( 15, $all['abandon_minutes'] );
        $this->assertSame( 365, $all['cart_retention_days'] );
        $this->assertSame( 90, $all['run_retention_days'] );
        $this->assertSame( '', $all['unsubscribe_text'] );
        $this->assertArrayNotHasKey( 'old_key', $all );
    }

    public function test_stored_text_is_sanitized_and_capped(): void {
        $this->store( [ 'unsubscribe_text' => '<b>Bye</b> ' . str_repeat( 'é', 1200 ) ] );

        $text = Settings::get( 'unsubscribe_text' );

        $this->assertSame( 1000, mb_strlen( $text ) );
        $this->assertStringStartsWith( 'Bye é', $text );
    }

    public function test_a_stored_non_array_reads_the_defaults(): void {
        $this->store( 'broken' );

        $this->assertSame( Settings::defaults(), Settings::all() );
    }

    public function test_get_returns_one_value_or_null(): void {
        $this->assertSame( 60, Settings::get( 'abandon_minutes' ) );
        $this->assertNull( Settings::get( 'nope' ) );
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

    public function test_update_saves_every_range_it_reads(): void {
        $result = Settings::update(
            [
                'abandon_minutes'     => 1440,
                'cart_retention_days' => 365,
                'run_retention_days'  => 730,
            ]
        );
        $this->assertSame( [ 1440, 365, 730 ], [ $result['abandon_minutes'], $result['cart_retention_days'], $result['run_retention_days'] ] );

        $result = Settings::update(
            [
                'abandon_minutes'     => 15,
                'cart_retention_days' => 7,
                'run_retention_days'  => 7,
            ]
        );
        $this->assertSame( [ 15, 7, 7 ], [ $result['abandon_minutes'], $result['cart_retention_days'], $result['run_retention_days'] ] );
    }

    /**
     * @dataProvider out_of_range
     * @param string $key
     * @param int    $value
     * @param string $message
     */
    public function test_update_refuses_a_number_outside_the_range( string $key, int $value, string $message ): void {
        $error = Settings::update( [ $key => $value ] );

        $this->assertInstanceOf( \WP_Error::class, $error );
        $this->assertSame(
            [
                [
                    'field'   => $key,
                    'message' => $message,
                ],
            ],
            $error->get_error_data()['errors']
        );
    }

    public function out_of_range(): array {
        return [
            'abandon below' => [ 'abandon_minutes', 14, 'Enter a whole number from 15 to 1440.' ],
            'abandon above' => [ 'abandon_minutes', 1441, 'Enter a whole number from 15 to 1440.' ],
            'carts below'   => [ 'cart_retention_days', 6, 'Enter a whole number from 7 to 365.' ],
            'carts above'   => [ 'cart_retention_days', 366, 'Enter a whole number from 7 to 365.' ],
            'runs below'    => [ 'run_retention_days', 6, 'Enter a whole number from 7 to 730.' ],
            'runs above'    => [ 'run_retention_days', 731, 'Enter a whole number from 7 to 730.' ],
        ];
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
        $this->assertSame( 'Choose on or off.', $data['errors'][3]['message'] );
        $this->assertSame( 'Enter text.', $data['errors'][5]['message'] );
        $this->assertSame( 30, Settings::get( 'abandon_minutes' ) );
    }

    public function test_values_stored_earlier_read_unchanged_and_survive_another_save(): void {
        $GLOBALS['yaymatik_test_options']['yaymatik_settings'] = [
            'abandon_minutes'     => 240,
            'cart_retention_days' => 365,
            'run_retention_days'  => 730,
        ];

        $saved = Settings::update( [ 'unsubscribe_text' => 'Bye' ] );

        $this->assertSame( [ 240, 365, 730 ], [ $saved['abandon_minutes'], $saved['cart_retention_days'], $saved['run_retention_days'] ] );
        $this->assertSame( 365, Settings::get( 'cart_retention_days' ) );
    }

    public function test_one_range_table_and_no_state_that_could_narrow_it(): void {
        $class = new \ReflectionClass( Settings::class );

        $this->assertSame(
            [
                'abandon_minutes'     => [ 15, 1440 ],
                'cart_retention_days' => [ 7, 365 ],
                'run_retention_days'  => [ 7, 730 ],
            ],
            $class->getConstant( 'RANGES' )
        );
        $this->assertSame( [ 'all' ], array_keys( $class->getStaticProperties() ), 'no static state beside the built settings' );
    }

    /**
     * @dataProvider plain_texts
     * @param string $typed
     * @param string $saved
     */
    public function test_update_stores_the_text_as_plain_text( string $typed, string $saved ): void {
        $this->assertSame( $saved, Settings::update( [ 'unsubscribe_text' => $typed ] )['unsubscribe_text'] );
        $this->assertSame( $saved, Settings::get( 'unsubscribe_text' ) );
    }

    public function plain_texts(): array {
        return [
            'percent kept'        => [ '100%ad-free', '100%ad-free' ],
            'lone < kept'         => [ 'a < b', 'a < b' ],
            'tags stripped'       => [ '<b>x</b>', 'x' ],
            'breaks kept, CRLF'   => [ " Bye\r\nSee you\n ", "Bye\nSee you" ],
            'capped, not refused' => [ str_repeat( 'a', 1001 ), str_repeat( 'a', 1000 ) ],
        ];
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
    }
}
