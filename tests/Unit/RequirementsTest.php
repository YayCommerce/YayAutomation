<?php
namespace YayMatik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YayMatik\Requirements;

/** The minimum WordPress, PHP and WooCommerce versions, checked before YayMatik loads. */
final class RequirementsTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_filters'], $GLOBALS['wp_version'] );
    }

    /**
     * @dataProvider versions
     * @param string      $wp
     * @param string      $php
     * @param string|null $wc
     * @param string[]    $missing
     */
    public function test_missing_lists_what_is_too_old( string $wp, string $php, ?string $wc, array $missing ): void {
        $this->assertSame( $missing, Requirements::missing( $wp, $php, $wc ) );
    }

    public function versions(): array {
        return [
            'all at the minimum'       => [ '6.9', '7.4.0', '11.0.0', [] ],
            'all newer'                => [ '7.1', '8.5.1', '11.2.2', [] ],
            'an RC of the minimums'    => [ '6.9-RC1', '7.4.0', '11.0.0-rc.1', [] ],
            'a WordPress beta is newer' => [ '7.1-beta1', '7.4.33', '11.0', [] ],
            'old WordPress'            => [ '6.8.3', '8.1.0', '11.2.2', [ 'WordPress 6.9' ] ],
            'old PHP'                  => [ '6.9', '7.3.33', '11.2.2', [ 'PHP 7.4' ] ],
            'old WooCommerce'          => [ '6.9', '8.1.0', '10.9.1', [ 'WooCommerce 11.0' ] ],
            'WooCommerce not active'   => [ '6.9', '8.1.0', null, [ 'WooCommerce 11.0' ] ],
            'all three'                => [ '6.5', '7.2.0', null, [ 'WordPress 6.9', 'PHP 7.4', 'WooCommerce 11.0' ] ],
        ];
    }

    public function test_the_plugin_header_states_the_same_minimums(): void {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
        $header = (string) file_get_contents( __DIR__ . '/../../yaymatik.php' );
        $field  = fn( string $name ) => preg_match( '/^ \* ' . preg_quote( $name, '/' ) . ': *(.+)$/m', $header, $m ) ? trim( $m[1] ) : null;

        $this->assertSame( Requirements::WP, $field( 'Requires at least' ) );
        $this->assertSame( Requirements::PHP, $field( 'Requires PHP' ) );
        $this->assertSame( Requirements::WC, $field( 'WC requires at least' ) );
        $this->assertSame( 'woocommerce', $field( 'Requires Plugins' ) );
    }

    public function test_met_reads_the_site_and_an_unmet_site_gets_a_notice(): void {
        if ( ! defined( 'WC_VERSION' ) ) {
            define( 'WC_VERSION', '11.2.2' );
        }
        $GLOBALS['wp_version'] = '6.9';
        $this->assertTrue( Requirements::met() );
        $this->assertArrayNotHasKey( 'admin_notices', $GLOBALS['yaymatik_test_filters'] ?? [] );

        $GLOBALS['wp_version'] = '6.8';
        $this->assertFalse( Requirements::met() );
        $this->assertCount( 1, $GLOBALS['yaymatik_test_filters']['admin_notices'] ?? [] );
        $this->assertCount( 1, $GLOBALS['yaymatik_test_filters']['network_admin_notices'] ?? [] );
    }
}
