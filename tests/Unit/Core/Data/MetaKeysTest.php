<?php
namespace YayMatik\Tests\Unit\Core\Data;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeWpdb;
use YayMatik\Workflow\Core\Data\MetaKeys;

/** User meta keys a workflow must not read or write. */
final class MetaKeysTest extends TestCase {

    /** @var mixed */
    private $wpdb;

    protected function setUp(): void {
        $this->wpdb = $GLOBALS['wpdb'] ?? null;
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here; MetaKeys reads its prefix.
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void {
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- puts back what setUp() replaced.
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    /**
     * @dataProvider blocked_keys
     * @param string $key
     */
    public function test_user_blocked( string $key ): void {
        $this->assertTrue( MetaKeys::user_blocked( $key ) );
    }

    public function blocked_keys(): array {
        return [
            'capabilities'       => [ 'wp_capabilities' ],
            'user level'         => [ 'wp_user_level' ],
            'user settings'      => [ 'wp_user-settings' ],
            'user settings time' => [ 'wp_user-settings-time' ],
            'a network site'     => [ 'wp_2_capabilities' ],
            'any case'           => [ 'WP_Capabilities' ],
            'sessions'           => [ 'session_tokens' ],
            'sessions, any case' => [ 'Session_Tokens' ],
            'own prefix'         => [ '_yaymatik_claim' ],
            'own prefix, upper'  => [ '_YAYMATIK_x' ],
        ];
    }

    /**
     * @dataProvider free_keys
     * @param string $key
     */
    public function test_user_free( string $key ): void {
        $this->assertFalse( MetaKeys::user_blocked( $key ) );
    }

    public function free_keys(): array {
        return [
            'a custom field'          => [ 'vip' ],
            'another prefix'          => [ 'xx_capabilities' ],
            'a suffix'                => [ 'wp_capabilities_backup' ],
            'a site id without _'     => [ 'wp_2capabilities' ],
            'a WooCommerce field'     => [ 'billing_phone' ],
            'close to the own prefix' => [ 'yaymatik_x' ],
        ];
    }

    public function test_the_base_prefix_is_quoted(): void {
        $GLOBALS['wpdb']->base_prefix = 'w.p_';
        $this->assertTrue( MetaKeys::user_blocked( 'w.p_capabilities' ) );
        $this->assertFalse( MetaKeys::user_blocked( 'wxp_capabilities' ) );
    }

    public function test_user_collides_when_the_collation_finds_a_blocked_row(): void {
        $GLOBALS['wpdb']->result = [ 'wp_capabilities' ];
        $this->assertTrue( MetaKeys::user_collides( 7, 'WP_CAPABILITIES' ) );
        $this->assertSame( "SELECT meta_key FROM wp_usermeta WHERE user_id = 7 AND meta_key = 'WP_CAPABILITIES'", $GLOBALS['wpdb']->calls[0][1] );
    }

    public function test_user_collides_on_any_row_even_beside_a_free_one(): void {
        $GLOBALS['wpdb']->result = [ 'wp_capabilitiés', 'wp_capabilities' ];
        $this->assertTrue( MetaKeys::user_collides( 7, 'wp_capabilitiés' ) );
    }

    public function test_user_collides_not_with_free_rows_or_none(): void {
        $GLOBALS['wpdb']->result = [ 'vip', 'VIP' ];
        $this->assertFalse( MetaKeys::user_collides( 7, 'vip' ) );
        $GLOBALS['wpdb']->result = [];
        $this->assertFalse( MetaKeys::user_collides( 7, 'new_key' ) );
    }

    public function test_a_failed_query_counts_as_a_collision(): void {
        $GLOBALS['wpdb']->result     = [];
        $GLOBALS['wpdb']->last_error = 'Table is gone';
        $this->assertTrue( MetaKeys::user_collides( 7, 'vip' ) );
    }
}
