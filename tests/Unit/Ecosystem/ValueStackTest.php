<?php
namespace YayMatik\Tests\Unit\Ecosystem;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeClock;
use YayMatik\Tests\Support\FakeGraphSource;
use YayMatik\Tests\Support\FakeOrder;
use YayMatik\Tests\Support\FakeQueue;
use YayMatik\Tests\Support\FakeRunRepository;
use YayMatik\Tests\Support\FakeWorkflows;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Runtime\Executor;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Extension\Declaration;
use YayMatik\Workflow\Extension\Extensions;
use YayMatik\Workflow\Extension\StepIndex;
use YayMatik\Workflow\ModuleRegistry;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__, 2 ) . '/Support/fake-wc.php';

/** The value stack's matrix (docs/extensions-roadmap.md) on the fake adapters: a grant once per run, prices untouched. */
final class ValueStackTest extends TestCase {

    /** Folder => class prefix. */
    private const PRODUCTS = [
        'yaypricing'   => 'YayPricing',
        'yaywholesale' => 'YayWholesale',
        'yayloyalty'   => 'YayLoyalty',
    ];

    /** Grant node => its product. */
    private const GRANTS = [
        'role'   => 'yaywholesale',
        'points' => 'yayloyalty',
    ];

    private const USER = 7;

    private const ORDER = 12;

    private const POINTS = 50;

    /** An order with a wholesale price and a YayPricing rule's discount. */
    private const PRICES = [
        'id'             => self::ORDER,
        'status'         => 'processing',
        'subtotal'       => '400.00',
        'discount_total' => '40.00',
        'total'          => '360.00',
        'line_items'     => [
            [
                'name'     => 'Crate (wholesale price)',
                'quantity' => 10,
                'total'    => '360.00',
            ],
        ],
    ];

    /** @var array Class => property => value, put back after. */
    private $saved = [
        ModuleRegistry::class => [ 'modules', 'built' ],
        StepRegistry::class   => [ 'steps' ],
        DataRegistry::class   => [ 'items', 'entities' ],
        Declaration::class    => [ 'items' ],
        StepIndex::class      => [ 'stored' ],
    ];

    /** @var FakeRunRepository */
    private $runs;

    /** @var FakeGraphSource */
    private $graphs;

    /** @var FakeWorkflows */
    private $workflows;

    /** @var array */
    private $users;

    /** @var array */
    private $filters;

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_options'] = [];
        $this->filters                    = $GLOBALS['yaymatik_test_filters'] ?? [];
        $this->users                      = $GLOBALS['yaymatik_test_users'] ?? [];
        $GLOBALS['yaymatik_test_users']   = [
            self::USER => (object) [
                'ID'    => self::USER,
                'roles' => [ 'customer' ],
                'meta'  => [],
            ],
        ];
        FakeOrder::$found[ self::ORDER ]  = new FakeOrder( self::PRICES );
        foreach ( $this->saved as $class => $names ) {
            $this->saved[ $class ] = [];
            foreach ( $names as $name ) {
                $this->saved[ $class ][ $name ] = self::get( $class, $name );
            }
        }
        self::set( StepIndex::class, 'stored', null );
        $this->runs      = new FakeRunRepository();
        $this->graphs    = new FakeGraphSource();
        $this->workflows = new FakeWorkflows();
    }

    protected function tearDown(): void {
        foreach ( $this->saved as $class => $values ) {
            foreach ( $values as $name => $value ) {
                self::set( $class, $name, $value );
            }
        }
        $GLOBALS['yaymatik_test_users']   = $this->users;
        $GLOBALS['yaymatik_test_filters'] = $this->filters;
        unset( FakeOrder::$found[ self::ORDER ] );
    }

    /**
     * @param string $class_name
     * @param string $name
     */
    private static function get( string $class_name, string $name ) {
        $property = new \ReflectionProperty( $class_name, $name );
        $property->setAccessible( true );
        return $property->getValue();
    }

    /**
     * @param string $class_name
     * @param string $name
     * @param mixed  $value
     */
    private static function set( string $class_name, string $name, $value ): void {
        $property = new \ReflectionProperty( $class_name, $name );
        $property->setAccessible( true );
        $property->setValue( null, $value );
    }

    /**
     * The site: all three were active when the workflow was saved; `$active` still are.
     *
     * @param string[] $active Product folders.
     */
    private function site( array $active ): void {
        $declare = function ( array $folders ) {
            return array_map(
                function ( string $folder ) {
                    $dir = dirname( __DIR__, 3 ) . "/dev/ecosystem/{$folder}/";
                    require_once $dir . self::PRODUCTS[ $folder ] . '_YayMatik.php';
                    require_once $dir . self::PRODUCTS[ $folder ] . '_YayMatik_Fake_Adapter.php';
                    $declarations = self::PRODUCTS[ $folder ] . '_YayMatik';
                    $adapter      = self::PRODUCTS[ $folder ] . '_YayMatik_Fake_Adapter';
                    return [ new $declarations( new $adapter() ), 'register' ];
                },
                $folders
            );
        };

        $core = ModuleRegistry::modules();
        StepIndex::refresh( Extensions::collect( array_keys( $core ), ...$declare( array_keys( self::PRODUCTS ) ) ) );
        // That collect added every product's data-field filters; a removed product's must not stay, as on a real site.
        $GLOBALS['yaymatik_test_filters'] = $this->filters;
        $modules                          = [];
        foreach ( Extensions::collect( array_keys( $core ), ...$declare( $active ) ) as $module ) {
            $modules[ $module->id() ] = $module;
        }
        self::set( ModuleRegistry::class, 'modules', $core + $modules );
        self::set( ModuleRegistry::class, 'built', null );
        self::set( StepRegistry::class, 'steps', null );
        self::set( DataRegistry::class, 'items', null );
        self::set( DataRegistry::class, 'entities', null );
    }

    /** Order paid → set wholesale role → award points → a check of YayPricing's discount. */
    private static function graph(): array {
        $node = fn( string $id, string $kind, string $block, array $config ) => [
            'id'   => $id,
            'kind' => $kind,
            'data' => [
                'block'  => $block,
                'config' => $config,
            ],
        ];
        $edge = fn( string $source, string $target ) => [
            'source' => $source,
            'target' => $target,
        ];
        return [
            'nodes' => [
                $node( 't', 'trigger', 'order_paid', [] ),
                $node( 'role', 'action', 'yaywholesale_set_role', [ 'role' => 'wholesale_gold' ] ),
                $node( 'points', 'action', 'yayloyalty_award_points', [ 'points' => self::POINTS ] ),
                $node(
                    'check',
                    'condition',
                    'condition',
                    [
                        'match' => 'all',
                        'rules' => [
                            [
                                'field'     => 'order.yaypricing_discount_total',
                                'operator'  => 'greater_than',
                                'value'     => '0',
                                'valueType' => 'value',
                            ],
                        ],
                    ]
                ),
            ],
            'edges' => [ $edge( 't', 'role' ), $edge( 'role', 'points' ), $edge( 'points', 'check' ) ],
        ];
    }

    /**
     * @param string $node
     */
    private static function graph_block( string $node ): string {
        return (string) array_column( self::graph()['nodes'], 'data', 'id' )[ $node ]['block'];
    }

    /**
     * A run of workflow `$workflow` on the order, as the background job starts it.
     *
     * @param int $workflow
     */
    private function start( int $workflow ): int {
        $this->graphs->graphs[ $workflow ]       = self::graph();
        $this->workflows->workflows[ $workflow ] = [
            'id'     => (string) $workflow,
            'status' => 'active',
            'graph'  => [],
        ];
        return $this->runs->create_run(
            [
                'workflow_id' => $workflow,
                'version_id'  => $workflow,
                'trigger_id'  => 'order_paid',
                'scope'       => [
                    'trigger' => [
                        'order'   => [
                            '__ref' => [
                                'type' => 'order',
                                'id'   => (string) self::ORDER,
                            ],
                        ],
                        'user_id' => self::USER,
                    ],
                ],
            ]
        );
    }

    /**
     * @param int $id
     */
    private function execute( int $id ): void {
        $this->runs->claim_run( $id, [ 'queued' ] );
        ( new Executor( $this->runs, $this->graphs, new FakeQueue(), $this->workflows, new FakeClock() ) )->run( $this->runs->find_run( $id ) );
    }

    /**
     * Each grant step's `handle` once more on its own run-step row: a retry of the attempt.
     *
     * @param int $id
     */
    private function retry_grants( int $id ): void {
        $run = $this->runs->find_run( $id );
        foreach ( $this->runs->steps( $id ) as $row ) {
            $step = isset( self::GRANTS[ $row['step_id'] ] ) ? StepRegistry::get( self::graph_block( (string) $row['step_id'] ) ) : null;
            if ( ! $step ) {
                continue;
            }
            $scope = Scope::from_array( (array) $run['scope'] );
            $scope->set_current_step( $id, (string) $row['step_id'], (int) $row['id'] );
            $this->assertTrue( $step->run( $scope, (array) $row['input'] )->is_ok(), "{$row['step_id']} retried" );
        }
    }

    /**
     * The run again from its start: every step with a row keeps its result.
     *
     * @param int $id
     */
    private function replay( int $id ): void {
        $this->runs->save_run(
            $id,
            [
                'status'   => 'queued',
                'position' => null,
            ]
        );
        $this->execute( $id );
    }

    /**
     * Every scenario × every mix of the three products active; a missing one was removed after the workflow was saved.
     *
     * @return array[] Cell name => [ scenario, active products ].
     */
    public function cells(): array {
        $cells    = [];
        $products = array_keys( self::PRODUCTS );
        foreach ( [ 'once', 'retried', 'replayed', 'two workflows' ] as $scenario ) {
            for ( $mask = 7; $mask >= 0; $mask-- ) {
                $active                         = array_values( array_filter( $products, fn( $folder, $i ) => (bool) ( $mask & ( 1 << $i ) ), ARRAY_FILTER_USE_BOTH ) );
                $mix                            = implode(
                    ', ',
                    array_map( fn( string $folder ) => $folder . ( in_array( $folder, $active, true ) ? ' on' : ' removed' ), $products )
                );
                $cells[ "{$scenario}: {$mix}" ] = [ $scenario, $active ];
            }
        }
        return $cells;
    }

    /**
     * @dataProvider cells
     * @param string   $scenario
     * @param string[] $active
     */
    public function test_cell( string $scenario, array $active ): void {
        $this->site( $active );

        $runs = [ $this->start( 9 ) ];
        if ( 'two workflows' === $scenario ) {
            $runs[] = $this->start( 10 );
        }
        foreach ( $runs as $id ) {
            $this->execute( $id );
        }
        if ( 'retried' === $scenario ) {
            $this->retry_grants( $runs[0] );
        }
        if ( 'replayed' === $scenario ) {
            $this->replay( $runs[0] );
        }

        $wholesale = in_array( 'yaywholesale', $active, true );
        $loyalty   = in_array( 'yayloyalty', $active, true );
        $pricing   = in_array( 'yaypricing', $active, true );
        $meta      = $GLOBALS['yaymatik_test_users'][ self::USER ]->meta;
        $this->assertSame( $wholesale ? 'wholesale_gold' : null, $meta['_yaywholesale_fake_role'] ?? null, 'role set once' );
        $this->assertSame( $loyalty ? (string) ( self::POINTS * count( $runs ) ) : null, $meta['_yayloyalty_fake_points'] ?? null, 'points awarded once per run' );
        $this->assertCount( $wholesale ? count( $runs ) : 0, (array) get_option( 'yaywholesale_fake_grants', [] ), 'one role grant per run' );
        $this->assertCount( $loyalty ? count( $runs ) : 0, (array) get_option( 'yayloyalty_fake_grants', [] ), 'one points grant per run' );

        $order = FakeOrder::$found[ self::ORDER ];
        $this->assertSame( self::PRICES, $order->data, 'prices as the products computed them' );
        $this->assertSame( 0, $order->saves, 'the order is never saved' );

        foreach ( $runs as $n => $id ) {
            $this->assertSame( 'completed', $this->runs->find_run( $id )['status'], 'never a cancelled or failed run' );
            $rows = array_column( $this->runs->steps( $id ), null, 'step_id' );
            $this->assertCount( 3, $this->runs->steps( $id ), 'one row per step, also after a replay' );
            $this->assertSame(
                $wholesale ? [ 'completed', [ 'role' => 'wholesale_gold' ], null ] : [ 'skipped', [], 'YayWholesale isn’t active.' ],
                [ $rows['role']['status'], $rows['role']['output'], $rows['role']['error'] ]
            );
            $this->assertSame(
                $loyalty
                    ? [
                        'completed',
                        [
                            'points_added' => self::POINTS,
                            'balance'      => self::POINTS * ( $n + 1 ),
                        ],
                        null,
                    ]
                    : [ 'skipped', [], 'YayLoyalty isn’t active.' ],
                [ $rows['points']['status'], $rows['points']['output'], $rows['points']['error'] ]
            );
            $this->assertSame(
                $pricing ? [ 'completed', [ 'result' => 'yes' ], null ] : [ 'skipped', [], 'YayPricing isn’t active.' ],
                [ $rows['check']['status'], $rows['check']['output'], $rows['check']['error'] ],
                'YayPricing’s discount read when it is on'
            );
        }
    }
}
