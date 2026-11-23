<?php
namespace YayMatik\Tests\Unit\Pro;

use PHPUnit\Framework\TestCase;
use YayMatik\Pro\YayMatikProLicenseAdapter;

/** The updater and the license check ask yaycommerce.com about YayMatik Pro's own item. */
final class YayMatikProLicenseAdapterTest extends TestCase {

    public function test_the_store_item_is_yaymatik_pro(): void {
        $adapter = new YayMatikProLicenseAdapter();

        $this->assertSame( 69307, $adapter->get_item_id() );
        $this->assertSame( 'yaymatik-pro', $adapter->get_plugin_slug() );
        $this->assertSame( 'https://yaycommerce.com/', $adapter->get_store_url() );
    }

    public function test_no_stand_in_id_marker_is_left(): void {
        $source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Pro/YayMatikProLicenseAdapter.php' );

        // release.sh refuses a store build on either marker.
        $this->assertStringNotContainsString( 'TODO(036)', $source );
        $this->assertStringNotContainsString( 'ITEM_ID_IS_TEMPORARY', $source );
    }
}
