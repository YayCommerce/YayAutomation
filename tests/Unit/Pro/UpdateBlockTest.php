<?php
namespace YayMatik\Tests\Unit\Pro;

use PHPUnit\Framework\TestCase;
use YayMatik\Pro\UpdateBlock;
use YayMatik\Pro\YayMatikProLicenseAdapter;

/** No update or details of the stand-in item while its id is temporary. */
final class UpdateBlockTest extends TestCase {

    public function test_the_item_id_is_still_the_temporary_one(): void {
        $this->assertTrue( YayMatikProLicenseAdapter::ITEM_ID_IS_TEMPORARY );
        $this->assertSame( 7761, YayMatikProLicenseAdapter::ITEM_ID );
    }

    public function test_an_update_of_this_plugin_moves_to_no_update(): void {
        $other                                         = (object) [ 'new_version' => '2.0' ];
        $value                                         = (object) [
            'response'  => [
                'yaymatik-pro/yaymatik-pro.php' => (object) [ 'new_version' => '3.3.5' ],
                'other/other.php'               => $other,
            ],
            'no_update' => [],
        ];
        $value = UpdateBlock::transient( $value );

        $this->assertSame( [ 'other/other.php' => $other ], $value->response );
        $this->assertArrayHasKey( 'yaymatik-pro/yaymatik-pro.php', $value->no_update );
    }

    public function test_an_empty_transient_is_unchanged(): void {
        $this->assertFalse( UpdateBlock::transient( false ) );
    }

    public function test_plugins_api_gets_back_what_it_had_before_the_updater(): void {
        $args = (object) [ 'slug' => 'yaymatik-pro' ];
        UpdateBlock::remember( false, 'plugin_information', $args );

        $this->assertFalse( UpdateBlock::restore( (object) [ 'name' => 'YayCurrency Pro' ], 'plugin_information', $args ) );
        $other = (object) [ 'name' => 'Other' ];
        $this->assertSame( $other, UpdateBlock::restore( $other, 'plugin_information', (object) [ 'slug' => 'other' ] ) );
    }
}
