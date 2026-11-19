<?php
namespace YayMatik\Tests\Unit\Core\Data;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeEntity;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\Filters;
use YayMatik\Workflow\Core\Data\GoneEntity;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Template;
use YayMatik\Workflow\Core\Data\Type;

/** Run scope: paths, shortcuts, relations, JSON round-trip, sample values, gone entities. */
final class ScopeTest extends TestCase {

    /** @var array[] Order id => order row. */
    private $orders = [
        '12' => [
            'id'     => 12,
            'number' => '1012',
            'total'  => 99.5,
            'status' => 'processing',
            'items'  => [ 501, 502 ],
            'first'  => 'Ann',
        ],
    ];

    /** @var array[] Item id => item row. */
    private $items = [
        '501' => [ 'name' => 'Mug' ],
        '502' => [ 'name' => 'Tea <b>' ],
    ];

    /** @var int[] Loads per ref key. */
    private $loads = [];

    private function entities(): array {
        $entities = [
            new FakeEntity(
                'order',
                [
                    'number'   => [
                        'label' => 'Number',
                        'type'  => DataItem::TYPE_STRING,
                        'value' => fn( $order ) => $order->number,
                    ],
                    'total'    => [
                        'label'      => 'Total',
                        'type'       => DataItem::TYPE_NUMBER,
                        'value_type' => Type::money(),
                        'value'      => fn( $order ) => $order->total,
                        'display'    => fn( $order, bool $html ) => $html ? '<span>$' . number_format( $order->total, 2 ) . '</span>' : '$' . number_format( $order->total, 2 ),
                    ],
                    'status'   => [
                        'label'   => 'Status',
                        'type'    => DataItem::TYPE_ENUM,
                        'value'   => fn( $order ) => $order->status,
                        'options' => fn() => [ 'processing' => 'Processing' ],
                    ],
                    'customer' => [
                        'label'       => 'Customer',
                        'type'        => DataItem::TYPE_STRING,
                        'value_type'  => Type::entity( 'customer' ),
                        'value'       => fn( $order ) => new Ref( 'customer', 'order:' . $order->id ),
                        'conditional' => false,
                    ],
                    'items'    => [
                        'label'       => 'Items',
                        'type'        => DataItem::TYPE_STRING,
                        'value_type'  => Type::list_of( Type::entity( 'order_item' ) ),
                        'value'       => fn( $order ) => array_map( fn( int $id ) => new Ref( 'order_item', $id ), $order->items ),
                        'conditional' => false,
                    ],
                ]
            ),
            new FakeEntity(
                'customer',
                [
                    'first_name' => [
                        'label' => 'First name',
                        'type'  => DataItem::TYPE_STRING,
                        'value' => fn( $order ) => $order->first,
                    ],
                ]
            ),
            new FakeEntity(
                'order_item',
                [
                    'name' => [
                        'label' => 'Name',
                        'type'  => DataItem::TYPE_STRING,
                        'value' => fn( $item ) => $item->name,
                    ],
                ]
            ),
            new FakeEntity(
                'site',
                [
                    'name' => [
                        'label' => 'Site name',
                        'type'  => DataItem::TYPE_STRING,
                        'value' => fn() => 'Yay Shop',
                    ],
                ]
            ),
        ];
        $by_id    = [];
        foreach ( $entities as $entity ) {
            $by_id[ $entity->id() ] = $entity;
        }
        return $by_id;
    }

    private function resolver(): RefResolver {
        $count = function ( string $key ) {
            $this->loads[ $key ] = ( $this->loads[ $key ] ?? 0 ) + 1;
        };
        return new RefResolver(
            [
                'order'      => function ( string $id ) use ( $count ) {
                    $count( 'order:' . $id );
                    return isset( $this->orders[ $id ] ) ? (object) $this->orders[ $id ] : null;
                },
                'customer'   => function ( string $id ) {
                    list( $kind, $number ) = explode( ':', $id, 2 );
                    return 'order' === $kind && isset( $this->orders[ $number ] ) ? (object) $this->orders[ $number ] : null;
                },
                'order_item' => fn( string $id ) => isset( $this->items[ $id ] ) ? (object) $this->items[ $id ] : null,
            ]
        );
    }

    private function scope( array $data ): Scope {
        return new Scope( $data, $this->resolver(), $this->entities() );
    }

    private function run_scope(): Scope {
        return $this->scope(
            [
                'trigger' => [
                    'order'       => new Ref( 'order', 12 ),
                    'customer'    => new Ref( 'customer', 'order:12' ),
                    'status_from' => 'pending',
                    'status_to'   => 'processing',
                ],
                'run'     => [ 'id' => 77 ],
            ]
        );
    }

    public function test_trigger_paths_and_shortcuts(): void {
        $scope = $this->run_scope();
        $this->assertSame( 99.5, $scope->get( 'trigger.order.total' ) );
        $this->assertSame( 99.5, $scope->get( 'order.total' ) );
        $this->assertSame( 'Ann', $scope->get( 'customer.first_name' ) );
        $this->assertSame( 'Ann', $scope->get( 'trigger.customer.first_name' ) );
        $this->assertSame( 'pending', $scope->get( 'trigger.status_from' ) );
        $this->assertInstanceOf( Ref::class, $scope->get( 'trigger.order' ) );
    }

    public function test_relations(): void {
        $scope = $this->run_scope();
        $this->assertSame( 'Ann', $scope->get( 'order.customer.first_name' ) );
        $this->assertSame( 'Mug', $scope->get( 'order.items.0.name' ) );
        $this->assertSame( 'Tea &lt;b&gt;', $scope->text( 'order.items.1.name', true ) );
        $this->assertCount( 2, $scope->get( 'order.items' ) );
        $this->assertSame( '', $scope->text( 'order.customer', false ) );
    }

    public function test_display_text(): void {
        $scope = $this->run_scope();
        $this->assertSame( '$99.50', $scope->text( 'order.total', false ) );
        $this->assertSame( '<span>$99.50</span>', $scope->text( 'order.total', true ) );
        $this->assertSame( 'Processing', $scope->text( 'order.status', false ) );
        $this->assertSame( 'processing', $scope->get( 'order.status' ) );
    }

    public function test_other_sections(): void {
        $scope = $this->run_scope();
        $scope->set_step_outputs( 'action-k2x9', [ 'code' => 'THANKS10' ] );
        $this->assertSame( 'THANKS10', $scope->get( 'steps.action-k2x9.code' ) );
        $this->assertSame( '77', $scope->text( 'run.id', false ) );
        $this->assertSame( 'Yay Shop', $scope->get( 'site.name' ) );
    }

    public function test_missing_paths(): void {
        $scope = $this->run_scope();
        foreach ( [ 'order.nope', 'steps.x.code', 'vars.none', 'nothing.here', 'site.nope', 'order.total.more', 'trigger.status_from.x' ] as $path ) {
            $this->assertNull( $scope->get( $path ), $path );
            $this->assertSame( '', $scope->text( $path, true ), $path );
        }
    }

    public function test_refs_load_once_per_resolver(): void {
        $scope = $this->run_scope();
        $scope->get( 'order.total' );
        $scope->get( 'order.number' );
        $scope->text( 'trigger.order.status', false );
        $this->assertSame( 1, $this->loads['order:12'] );
    }

    public function test_json_round_trip(): void {
        $scope = $this->run_scope();
        $scope->set_step_outputs( 'coupon', [ 'code' => 'X1' ] );

        $json = json_encode( $scope->to_array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- plain PHP test.
        $this->assertStringContainsString( '{"__ref":{"type":"order","id":"12"}}', $json );

        $stored   = json_decode( $json, true );
        $restored = Scope::from_array( $stored, $this->resolver(), $this->entities() );
        $this->assertSame( $stored, $restored->to_array() );
        $this->assertSame( 'Ann', $restored->get( 'order.customer.first_name' ) );
        $this->assertSame( 'pending', $restored->get( 'trigger.status_from' ) );
        $this->assertSame( 'X1', $restored->get( 'steps.coupon.code' ) );
    }

    public function test_sample_values_need_no_refs(): void {
        $scope = $this->scope(
            [
                'trigger' => [
                    'order' => [
                        'total'    => 25,
                        'number'   => '<1001>',
                        'customer' => [ 'first_name' => 'Bo' ],
                        'paid'     => true,
                        'tags'     => [ 'vip', 'new' ],
                    ],
                ],
            ]
        );
        $this->assertSame( 25, $scope->get( 'order.total' ) );
        $this->assertSame( 'Bo', $scope->get( 'order.customer.first_name' ) );
        $this->assertSame( '&lt;1001&gt;', $scope->text( 'order.number', true ) );
        $this->assertSame( 'Yes', $scope->text( 'order.paid', false ) );
        $this->assertSame( 'vip, new', $scope->text( 'order.tags', false ) );
        $this->assertSame( [], $this->loads );
    }

    public function test_gone_entity_throws(): void {
        $scope = $this->scope( [ 'trigger' => [ 'order' => new Ref( 'order', 99 ) ] ] );
        $this->assertNull( $scope->get( 'customer.first_name' ) );
        try {
            $scope->get( 'order.total' );
            $this->fail( 'Expected GoneEntity' );
        } catch ( GoneEntity $gone ) {
            $this->assertSame( 'order:99', $gone->ref()->key() );
        }
        $this->expectException( GoneEntity::class );
        $scope->text( 'order.number', false );
    }

    public function test_unknown_entity_type_is_missing_and_no_loader_is_gone(): void {
        $scope = $this->scope( [ 'trigger' => [ 'order' => new Ref( 'product', 5 ) ] ] );
        $this->assertNull( $scope->get( 'order.name' ) );

        $this->expectException( GoneEntity::class );
        ( new RefResolver( [] ) )->load( new Ref( 'order', 1 ) );
    }

    public function test_anything_the_trigger_supplied_is_a_shortcut(): void {
        $scope = $this->scope(
            [
                'trigger' => [
                    'product' => [ 'name' => 'Mug' ],
                    'from'    => 'pending',
                ],
            ]
        );
        $this->assertSame( 'Mug', $scope->get( 'product.name' ) );
        $this->assertSame( 'pending', $scope->get( 'from' ) );
        $this->assertNull( $scope->get( 'order.total' ) );
    }

    public function test_only_trigger_refs_are_read_back(): void {
        $marker = ( new Ref( 'order', 12 ) )->to_json();
        $stored = [
            'trigger' => [ 'order' => $marker ],
            'steps'   => [ 'webhook' => [ 'payload' => $marker ] ],
        ];
        $scope  = Scope::from_array( $stored, $this->resolver(), $this->entities() );
        $this->assertSame( 99.5, $scope->get( 'order.total' ) );
        $this->assertNull( $scope->get( 'steps.webhook.payload.total' ) );
        $this->assertSame( $marker, $scope->get( 'steps.webhook.payload' ) );
    }

    public function test_text_reads_only_the_display_when_a_field_has_one(): void {
        $calls    = 0;
        $entities = $this->entities() + [
            'counted' => new FakeEntity(
                'counted',
                [
                    'shown' => [
                        'label'   => 'Shown',
                        'type'    => DataItem::TYPE_STRING,
                        'value'   => function () use ( &$calls ) {
                            ++$calls;
                            return 'value';
                        },
                        'display' => fn() => 'display',
                    ],
                ]
            ),
        ];
        $resolver = new RefResolver( [ 'counted' => fn() => (object) [] ] );
        $scope    = new Scope( [ 'trigger' => [ 'counted' => new Ref( 'counted', 1 ) ] ], $resolver, $entities );
        $this->assertSame( 'display', $scope->text( 'counted.shown', false ) );
        $this->assertSame( 0, $calls );
        $this->assertSame( 'value', $scope->get( 'counted.shown' ) );
        $this->assertSame( 1, $calls );
    }

    public function test_a_field_shows_its_display_text_when_its_value_is_empty(): void {
        $entities = $this->entities() + [
            'paid' => new FakeEntity(
                'paid',
                [
                    'method' => [
                        'label'   => 'Method',
                        'type'    => DataItem::TYPE_STRING,
                        'value'   => fn() => null,
                        'display' => fn() => 'Cash',
                    ],
                ]
            ),
        ];
        $resolver = new RefResolver( [ 'paid' => fn() => (object) [] ] );
        $scope    = new Scope( [ 'trigger' => [ 'paid' => new Ref( 'paid', 1 ) ] ], $resolver, $entities );
        $this->assertNull( $scope->get( 'paid.method' ) );
        $this->assertSame( 'Cash', $scope->text( 'paid.method', false ) );
    }

    public function test_template_reads_through_scope(): void {
        $template = new Template( new Filters( null, null, new \DateTimeZone( 'UTC' ) ) );
        $this->assertSame(
            'Hi ANN, order 1012: $99.50 at Yay Shop',
            $template->render( 'Hi {{ order.customer.first_name | upper }}, order {{ order.number }}: {{ order.total }} at {{ site.name }}', $this->run_scope() )
        );
    }

    /**
     * A scope over one object with these date fields.
     *
     * @param array $fields
     */
    private function dated_scope( array $fields ): Scope {
        $entity = new FakeEntity( 'thing', $fields );
        return new Scope(
            [ 'trigger' => [ 'thing' => new Ref( 'thing', '1' ) ] ],
            new RefResolver( [ 'thing' => fn() => (object) [ 'at' => 1234 ] ] ),
            [ 'thing' => $entity ]
        );
    }

    public function test_timestamp_reads_the_fields_own_moment(): void {
        $scope = $this->dated_scope(
            [
                'when' => [
                    'label'     => 'When',
                    'type'      => DataItem::TYPE_DATE,
                    'value'     => fn() => '2026-01-02',
                    'timestamp' => fn( $thing ) => $thing->at,
                ],
            ]
        );
        $this->assertSame( 1234, $scope->timestamp( 'thing.when' ) );
    }

    public function test_timestamp_without_the_prop_is_midnight_in_the_store_time_zone(): void {
        $scope = $this->dated_scope(
            [
                'when'  => [
                    'label' => 'When',
                    'type'  => DataItem::TYPE_DATE,
                    'value' => fn() => '2026-01-02',
                ],
                'never' => [
                    'label' => 'Never',
                    'type'  => DataItem::TYPE_DATE,
                    'value' => fn() => '',
                ],
            ]
        );
        update_option( 'timezone_string', 'Asia/Ho_Chi_Minh' );
        try {
            $this->assertSame( gmmktime( 17, 0, 0, 1, 1, 2026 ), $scope->timestamp( 'thing.when' ) );
        } finally {
            update_option( 'timezone_string', 'UTC' );
        }
        $this->assertNull( $scope->timestamp( 'thing.never' ) );
        $this->assertNull( $scope->timestamp( 'thing.missing' ) );
    }

    /** A trigger with two date facts: `at` stored as a Unix time, `day` as a `Y-m-d`, like an extension's. */
    private static function dated_trigger(): \YayMatik\Workflow\Core\Steps\Trigger {
        return \YayMatik\Workflow\Extension\Extensions::collect(
            [],
            fn( $registrar ) => $registrar->module(
                [
                    'id'    => 'acme',
                    'label' => 'Acme',
                ]
            )->trigger(
                'acme_signed_in',
                [
                    'label'       => 'Signed in',
                    'description' => 'When someone signs in.',
                    'provides'    => [ 'customer' ],
                    'facts'       => [
                        'at'  => [
                            'label' => 'At',
                            'type'  => 'date',
                        ],
                        'day' => [
                            'label' => 'Day',
                            'type'  => 'date',
                        ],
                    ],
                ]
            )
        )[0]->triggers()[0];
    }

    public function test_a_date_fact_stored_as_a_unix_time_reads_as_its_day_and_keeps_its_moment(): void {
        // 2026-10-12 23:59:59 UTC.
        $at    = gmmktime( 23, 59, 59, 10, 12, 2026 );
        $scope = new Scope(
            [
                'trigger' => [
                    'at'  => $at,
                    'day' => '2026-10-12',
                ],
            ],
            null,
            []
        );
        $scope->set_trigger( self::dated_trigger() );

        $this->assertSame( '2026-10-12', $scope->get( 'trigger.at' ) );
        $this->assertSame( '2026-10-12', $scope->text( 'trigger.at', false ) );
        $this->assertSame( '2026-10-12', ( new Template() )->render( '{{ trigger.at }}', $scope ) );
        $this->assertSame( $at, $scope->moment( 'trigger.at' ) );
        $this->assertSame( $at, $scope->timestamp( 'trigger.at' ), 'in_last reads the exact moment' );

        $this->assertSame( '2026-10-12', $scope->get( 'trigger.day' ), 'a Y-m-d fact as today' );
        $this->assertNull( $scope->moment( 'trigger.day' ), 'a day is not a moment' );
        $this->assertSame( gmmktime( 0, 0, 0, 10, 12, 2026 ), $scope->timestamp( 'trigger.day' ) );
        $this->assertNull( $scope->moment( 'trigger.missing' ) );
    }

    public function test_without_its_trigger_an_int_fact_stays_a_number(): void {
        $scope = new Scope( [ 'trigger' => [ 'at' => 5 ] ], null, [] );
        $this->assertSame( 5, $scope->get( 'trigger.at' ) );
        $this->assertNull( $scope->moment( 'trigger.at' ) );
    }

    public function test_moment_of_a_field_is_its_timestamp_only(): void {
        $scope = $this->dated_scope(
            [
                'exact' => [
                    'label'     => 'Exact',
                    'type'      => DataItem::TYPE_DATE,
                    'value'     => fn() => '2026-01-02',
                    'timestamp' => fn( $thing ) => $thing->at,
                ],
                'day'   => [
                    'label' => 'Day',
                    'type'  => DataItem::TYPE_DATE,
                    'value' => fn() => '2026-01-02',
                ],
            ]
        );
        $this->assertSame( 1234, $scope->moment( 'thing.exact' ) );
        $this->assertNull( $scope->moment( 'thing.day' ) );
    }
}
