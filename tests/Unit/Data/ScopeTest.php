<?php
namespace YayAutomation\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use YayAutomation\Tests\Support\FakeEntity;
use YayAutomation\Workflow\Data\DataItem;
use YayAutomation\Workflow\Data\Filters;
use YayAutomation\Workflow\Data\GoneEntity;
use YayAutomation\Workflow\Data\Ref;
use YayAutomation\Workflow\Data\RefResolver;
use YayAutomation\Workflow\Data\Scope;
use YayAutomation\Workflow\Data\Template;
use YayAutomation\Workflow\Data\Type;

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

    /**
     * @param array $data Scope data.
     */
    private function scope( array $data ): Scope {
        return new Scope( $data, $this->resolver(), $this->entities() );
    }

    private function run_scope(): Scope {
        return $this->scope(
            [
                'trigger'  => [
                    'order'       => new Ref( 'order', 12 ),
                    'customer'    => new Ref( 'customer', 'order:12' ),
                    'status_from' => 'pending',
                    'status_to'   => 'processing',
                ],
                'workflow' => [
                    'id'   => 3,
                    'name' => 'Thank you',
                ],
                'run'      => [ 'id' => 77 ],
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
        $scope->set_var( 'tier', 'gold' );
        $this->assertSame( 'THANKS10', $scope->get( 'steps.action-k2x9.code' ) );
        $this->assertSame( 'gold', $scope->get( 'vars.tier' ) );
        $this->assertSame( 'Thank you', $scope->get( 'workflow.name' ) );
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
            'vars'    => [ 'v' => $marker ],
        ];
        $scope  = Scope::from_array( $stored, $this->resolver(), $this->entities() );
        $this->assertSame( 99.5, $scope->get( 'order.total' ) );
        $this->assertNull( $scope->get( 'steps.webhook.payload.total' ) );
        $this->assertNull( $scope->get( 'vars.v.total' ) );
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

    public function test_template_reads_through_scope(): void {
        $template = new Template( new Filters( null, null, new \DateTimeZone( 'UTC' ) ) );
        $this->assertSame(
            'Hi ANN, order 1012: $99.50 at Yay Shop',
            $template->render( 'Hi {{ order.customer.first_name | upper }}, order {{ order.number }}: {{ order.total }} at {{ site.name }}', $this->run_scope() )
        );
    }
}
