<?php
namespace YayMatik\Tests\Unit\Pro\Engine\Privacy;

use PHPUnit\Framework\TestCase;
use YayMatik\Pro\Engine\Privacy\ActivityData;
use YayMatik\Pro\Workflow\Core\Cart\CartActivity;
use YayMatik\Pro\Workflow\Core\Session\LastSeen;

require_once __DIR__ . '/privacy-stubs.php';

/** WordPress's export and erase of what Pro keeps on an account. */
final class ActivityDataTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_users'] = [
            7 => (object) [
                'ID'         => 7,
                'user_email' => 'jane@example.test',
                'meta'       => [
                    LastSeen::KEY      => '1791799200',
                    CartActivity::META => '1791802800',
                ],
            ],
        ];
    }

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_users'] );
    }

    public function test_registered_beside_other_plugins(): void {
        $this->assertSame( [ 'other', 'yaymatik-pro' ], array_keys( ActivityData::exporters( [ 'other' => [] ] ) ) );
        $this->assertSame( [ 'other', 'yaymatik-pro' ], array_keys( ActivityData::erasers( [ 'other' => [] ] ) ) );
    }

    public function test_export_lists_both_times(): void {
        $export = ActivityData::export( 'jane@example.test' );
        $this->assertTrue( $export['done'] );
        $this->assertSame(
            [
                [
                    'name'  => 'Last seen on the store',
                    'value' => '2026-10-12 10:00:00',
                ],
                [
                    'name'  => 'Last added to cart',
                    'value' => '2026-10-12 11:00:00',
                ],
            ],
            $export['data'][0]['data']
        );
        $this->assertSame( [], ActivityData::export( 'nobody@example.test' )['data'] );
    }

    public function test_erase_removes_both(): void {
        $erased = ActivityData::erase( 'jane@example.test' );
        $this->assertTrue( $erased['items_removed'] );
        $this->assertSame( [], $GLOBALS['yaymatik_test_users'][7]->meta );
        $this->assertFalse( ActivityData::erase( 'jane@example.test' )['items_removed'], 'nothing left' );
        $this->assertFalse( ActivityData::erase( 'nobody@example.test' )['items_removed'] );
    }
}
