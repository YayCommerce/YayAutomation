<?php
namespace YayMatik\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Extension\Diagnostics;
use YayMatik\Workflow\Extension\ExtensionDataItem;
use YayMatik\Workflow\Extension\Extensions;

/** A module's own data item: load, fields, sample; a refused one is left out. */
final class ExtensionDataItemTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_options'] = [];
    }

    /**
     * Module `acme`'s data items after this declaration.
     *
     * @param array $args
     * @return ExtensionDataItem[]
     */
    private static function items( array $args ): array {
        return Extensions::collect(
            [],
            fn( $registrar ) => $registrar->module(
                [
                    'id'    => 'acme',
                    'label' => 'Acme',
                ]
            )->data_item(
                'acme_reward',
                $args + [
                    'label'       => 'Reward',
                    'description' => 'A redeemed reward.',
                ]
            )
        )[0]->data_items();
    }

    public function test_load_fields_and_sample(): void {
        $item = self::items(
            [
                'icon'   => 'gift',
                'load'   => fn( string $id ) => '7' === $id ? (object) [ 'points' => 500 ] : null,
                'fields' => [
                    'points' => [
                        'label' => 'Points',
                        'type'  => 'number',
                        'value' => fn( $reward ) => $reward->points,
                    ],
                ],
                'sample' => [ 'points' => 250 ],
            ]
        )['acme_reward'];

        $this->assertSame( [ 'acme_reward', 'Reward', 'gift' ], [ $item->id(), $item->label(), $item->icon() ] );
        $this->assertSame( 500, ( $item->fields()['points']['value'] )( $item->load( '7' ) ) );
        $this->assertNull( $item->load( '8' ), 'gone' );
        $this->assertSame( [ 'points' => 250 ], $item->sample() );
    }

    public function test_a_throwing_load_is_gone_with_a_diagnostic(): void {
        $item = self::items(
            [
                'load' => function () {
                    throw new \RuntimeException( 'Rewards table missing.' );
                },
            ]
        )['acme_reward'];

        $this->assertNull( $item->load( '7' ) );
        $this->assertSame( [ 'load acme_reward', 'Rewards table missing.' ], [ Diagnostics::recent( 24 )[0]['callback'], Diagnostics::recent( 24 )[0]['message'] ] );
    }

    public function test_refused_without_load_or_with_a_taken_id(): void {
        $this->assertSame( [], self::items( [] ) );
        $this->assertSame( '"load" must be a callable.', Diagnostics::recent( 24 )[0]['message'] );
    }
}
