<?php
namespace YayMatik\Tests\Unit\Pro\Workflow\Core\Cart;

use PHPUnit\Framework\TestCase;
use YayMatik\Pro\Workflow\Core\Cart\CartActivity;
use YayMatik\Pro\Workflow\Core\Session\LastSeen;
use YayMatik\Tests\Support\FakeWpdb;
use YayMatik\Workflow\Core\Storage\Settings;

/** Last added to cart: what counts as an add, the time tracking resumed, items in cart. */
final class CartActivityTest extends TestCase {

    /** @var array Stub hooks before the test. */
    private $hooks;

    /** @var FakeWpdb */
    private $db;

    protected function setUp(): void {
        $this->hooks                      = $GLOBALS['yaymatik_test_filters'] ?? [];
        $GLOBALS['yaymatik_test_options'] = [];
        $GLOBALS['yaymatik_test_users']   = [ 7 => (object) [ 'meta' => [] ] ];
        $GLOBALS['yaymatik_test_user_id'] = 7;
        $this->db                         = new FakeWpdb();
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
        $GLOBALS['wpdb'] = $this->db;
    }

    protected function tearDown(): void {
        $GLOBALS['yaymatik_test_filters'] = $this->hooks;
        $GLOBALS['yaymatik_test_options'] = [];
        unset( $GLOBALS['yaymatik_test_users'], $GLOBALS['yaymatik_test_user_id'] );
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    /** Settings are read once per request: forget them after a change. */
    private static function forget_settings(): void {
        $built = new \ReflectionProperty( Settings::class, 'all' );
        $built->setAccessible( true );
        $built->setValue( null, null );
    }

    /**
     * @param string $name
     * @return mixed
     */
    private static function option( string $name ) {
        return $GLOBALS['yaymatik_test_options'][ $name ] ?? null;
    }

    public function test_the_first_licensed_boot_starts_tracking_and_hooks_the_cart(): void {
        CartActivity::register( true );
        $this->assertGreaterThan( time() - 5, self::option( CartActivity::RESUMED ) );
        $this->assertSame( '1', self::option( CartActivity::LICENSED ) );
        $this->assertNotEmpty( $GLOBALS['yaymatik_test_filters']['woocommerce_add_to_cart'] ?? [] );
        $this->assertNotEmpty( $GLOBALS['yaymatik_test_filters']['woocommerce_after_cart_item_quantity_update'] ?? [] );
        $this->assertNotEmpty( $GLOBALS['yaymatik_test_filters'][ 'deactivate_' . YAYMATIK_BASE_NAME ] ?? [] );
    }

    public function test_a_later_licensed_boot_keeps_the_resume_time(): void {
        $GLOBALS['yaymatik_test_options'] = [
            CartActivity::RESUMED  => 1000,
            CartActivity::LICENSED => '1',
        ];
        CartActivity::register( true );
        $this->assertSame( 1000, self::option( CartActivity::RESUMED ) );
    }

    public function test_reactivation_resumes(): void {
        $GLOBALS['yaymatik_test_options'] = [
            CartActivity::RESUMED  => 1000,
            CartActivity::LICENSED => '1',
        ];
        CartActivity::pause();
        CartActivity::register( true );
        $this->assertGreaterThan( 1000, self::option( CartActivity::RESUMED ), 'N28' );
        $this->assertSame( '0', self::option( CartActivity::PAUSED ) );
    }

    public function test_a_license_allowed_again_resumes_and_none_tracks_nothing(): void {
        $GLOBALS['yaymatik_test_options'] = [
            CartActivity::RESUMED  => 1000,
            CartActivity::LICENSED => '1',
        ];
        $before                           = $GLOBALS['yaymatik_test_filters']['woocommerce_add_to_cart'] ?? [];
        CartActivity::register( false );
        $this->assertSame( '0', self::option( CartActivity::LICENSED ) );
        $this->assertSame( $before, $GLOBALS['yaymatik_test_filters']['woocommerce_add_to_cart'] ?? [], 'no cart hooks without a license' );
        $this->assertSame( 1000, self::option( CartActivity::RESUMED ) );

        CartActivity::register( true );
        $this->assertGreaterThan( 1000, self::option( CartActivity::RESUMED ), 'N29' );
    }

    public function test_an_add_or_a_higher_quantity_counts_for_the_signed_in_person(): void {
        CartActivity::added();
        $this->assertGreaterThan( time() - 5, (int) $GLOBALS['yaymatik_test_users'][7]->meta[ CartActivity::META ], 'N24' );

        $GLOBALS['yaymatik_test_users'][7]->meta = [];
        CartActivity::quantity( 'k', 3, 2 );
        $this->assertArrayHasKey( CartActivity::META, $GLOBALS['yaymatik_test_users'][7]->meta, 'N25' );

        $GLOBALS['yaymatik_test_users'][7]->meta = [];
        CartActivity::quantity( 'k', 1, 2 );
        CartActivity::quantity( 'k', 2, 2 );
        $this->assertSame( [], $GLOBALS['yaymatik_test_users'][7]->meta, 'G1, G3: lower or the same' );

        $GLOBALS['yaymatik_test_user_id'] = 0;
        CartActivity::added();
        $this->assertSame( [], $GLOBALS['yaymatik_test_users'][7]->meta, 'a guest' );
    }

    public function test_last_added_is_never_earlier_than_the_resume_time(): void {
        $GLOBALS['yaymatik_test_users'][7]->meta = [ CartActivity::META => '2000' ];
        $GLOBALS['yaymatik_test_options']        = [ CartActivity::RESUMED => 1000 ];
        $this->assertSame( 2000, CartActivity::last_added( 7 ) );
        $GLOBALS['yaymatik_test_options'] = [ CartActivity::RESUMED => 3000 ];
        $this->assertSame( 3000, CartActivity::last_added( 7 ), 'a tracking gap reads as an add' );
        $GLOBALS['yaymatik_test_options']        = [];
        $GLOBALS['yaymatik_test_users'][7]->meta = [];
        $this->assertNull( CartActivity::last_added( 7 ), 'neither known' );
        $this->assertNull( CartActivity::last_added( 0 ), 'a guest' );
    }

    public function test_items_in_cart_sum_the_quantities_of_the_cart_not_ordered(): void {
        self::forget_settings();
        $this->assertNull( CartActivity::item_count( 7 ), 'Track carts off' );

        update_option( 'yaymatik_settings', [ 'cart_tracking' => true ] );
        self::forget_settings();
        $this->db->results = [ '[{"product_id":1,"quantity":2},{"product_id":2,"quantity":3}]', null ];
        $this->assertSame( 5.0, CartActivity::item_count( 7 ) );
        $this->assertStringContainsString( "WHERE user_id = 7 AND status <> 'ordered'", $this->db->last_sql() );
        $this->assertSame( 0.0, CartActivity::item_count( 7 ), 'no cart' );
        $this->assertNull( CartActivity::item_count( 0 ) );
        $GLOBALS['yaymatik_test_options'] = [];
        self::forget_settings();
    }

    public function test_every_new_key_has_the_prefix_uninstall_removes(): void {
        foreach ( [ LastSeen::KEY, CartActivity::META ] as $meta ) {
            $this->assertStringStartsWith( '_yaymatik_', $meta );
        }
        foreach ( [ CartActivity::RESUMED, CartActivity::PAUSED, CartActivity::LICENSED ] as $option ) {
            $this->assertStringStartsWith( 'yaymatik_', $option );
        }
    }
}
