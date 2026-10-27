<?php
namespace YayMatik\Tests\Unit\Core\Storage;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Storage\Settings;

/** Settings read from and saved to the option. */
final class SettingsTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_options'] );
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
    }
}
