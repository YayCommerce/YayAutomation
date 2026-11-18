<?php
namespace YayMatik\Tests\Unit\Core\Storage;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Storage\Settings;

/** Settings read from and saved to the option. */
final class SettingsTest extends TestCase {

    /** @var array[] The save ranges the run started with: YayMatik Pro widens them in the bootstrap. */
    private $editable = [];

    protected function setUp(): void {
        $this->forget_built();
        $ranges         = new \ReflectionProperty( Settings::class, 'editable' );
        $this->editable = $ranges->getValue();
        // The free edition's.
        $ranges->setValue( null, $ranges->getDeclaringClass()->getDefaultProperties()['editable'] );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_options'] );
        $this->forget_built();
        ( new \ReflectionProperty( Settings::class, 'editable' ) )->setValue( null, $this->editable );
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

    public function test_the_free_edition_saves_retention_up_to_60_and_90_days(): void {
        $this->assertSame( 60, Settings::update( [ 'cart_retention_days' => 60 ] )['cart_retention_days'] );
        $this->assertSame( 90, Settings::update( [ 'run_retention_days' => 90 ] )['run_retention_days'] );

        $error = Settings::update(
            [
                'cart_retention_days' => 61,
                'run_retention_days'  => 91,
            ]
        );
        $this->assertSame( [ 'Enter a whole number from 7 to 60.', 'Enter a whole number from 7 to 90.' ], array_column( $error->get_error_data()['errors'], 'message' ) );
    }

    public function test_the_free_edition_leaves_abandon_minutes_as_stored(): void {
        $GLOBALS['yaymatik_test_options']['yaymatik_settings'] = [ 'abandon_minutes' => 30 ];

        $this->assertSame( 30, Settings::update( [ 'abandon_minutes' => 120 ] )['abandon_minutes'] );
    }

    public function test_values_stored_by_pro_keep_working_until_the_owner_saves_a_new_one(): void {
        $GLOBALS['yaymatik_test_options']['yaymatik_settings'] = [
            'abandon_minutes'     => 240,
            'cart_retention_days' => 365,
            'run_retention_days'  => 730,
        ];

        $saved = Settings::update( [ 'unsubscribe_text' => 'Bye' ] );

        $this->assertSame( [ 240, 365, 730 ], [ $saved['abandon_minutes'], $saved['cart_retention_days'], $saved['run_retention_days'] ] );
        $this->assertSame( 365, Settings::get( 'cart_retention_days' ) );
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
