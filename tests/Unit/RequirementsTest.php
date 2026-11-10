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
            'a WordPress beta is newer' => [ '7.1-beta1', '7.4.33', '11.0', [] ],
            'old WordPress'            => [ '6.8.3', '8.1.0', '11.2.2', [ 'WordPress 6.9' ] ],
            'old PHP'                  => [ '6.9', '7.3.33', '11.2.2', [ 'PHP 7.4' ] ],
            'old WooCommerce'          => [ '6.9', '8.1.0', '10.9.1', [ 'WooCommerce 11.0' ] ],
            'WooCommerce not active'   => [ '6.9', '8.1.0', null, [ 'WooCommerce 11.0' ] ],
            'all three'                => [ '6.5', '7.2.0', null, [ 'WordPress 6.9', 'PHP 7.4', 'WooCommerce 11.0' ] ],
        ];
    }

    public function test_an_unmet_site_gets_a_notice_and_does_not_load(): void {
        $GLOBALS['wp_version'] = '6.8';
        $this->assertFalse( Requirements::met() );
        $this->assertCount( 1, $GLOBALS['yaymatik_test_filters']['admin_notices'] ?? [] );
    }
}
