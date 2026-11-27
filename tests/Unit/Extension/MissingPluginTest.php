<?php
namespace YayMatik\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Definition\Catalog;
use YayMatik\Workflow\Core\Definition\ProCatalog;
use YayMatik\Workflow\Core\Definition\GraphSchema;
use YayMatik\Workflow\Core\Definition\Messages;
use YayMatik\Workflow\Core\Definition\Validator;
use YayMatik\Workflow\Core\Runtime\HealthCheck;
use YayMatik\Workflow\Extension\Extensions;
use YayMatik\Workflow\Extension\StepIndex;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** A workflow using a step of a plugin that is off (docs/extension-api.md §10). */
final class MissingPluginTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_options'] = [];
        self::forget_index();
        // Acme was active once: its steps are in the index, not in the registry.
        StepIndex::refresh(
            Extensions::collect(
                [],
                function ( $registrar ) {
                    $module = $registrar->module(
                        [
                            'id'    => 'acme',
                            'label' => 'Acme Rewards',
                        ]
                    );
                    $module->action(
                        'acme_add_points',
                        [
                            'label'       => 'Add reward points',
                            'description' => 'Adds points.',
                            'outputs'     => [
                                'balance' => [
                                    'label' => 'New balance',
                                    'type'  => 'number',
                                ],
                            ],
                            'handle'      => fn( array $config, $step ) => $step->done(),
                        ]
                    );
                }
            )
        );
    }

    protected function tearDown(): void {
        self::forget_index();
    }

    private static function forget_index(): void {
        $stored = new \ReflectionProperty( StepIndex::class, 'stored' );
        $stored->setAccessible( true );
        $stored->setValue( null, null );
    }

    /**
     * Order created → acme_add_points (`a`) → note (`n`) reading `$note`.
     *
     * @param string $note
     * @param array  $config
     */
    private static function graph( string $note = 'Hi', array $config = [ 'points' => 10 ] ): array {
        $node = fn( string $id, string $kind, ?string $block, array $data_config ) => [
            'id'       => $id,
            'kind'     => $kind,
            'position' => [
                'x' => 0,
                'y' => 0,
            ],
            'data'     => [
                'block'  => $block,
                'config' => $data_config,
            ],
        ];
        return [
            'nodes' => [ $node( 't', 'trigger', 'order_created', [] ), $node( 'a', 'action', 'acme_add_points', $config ), $node( 'n', 'action', 'add_order_note', [ 'note' => $note ] ) ],
            'edges' => [
                [
                    'id'     => 'e1',
                    'source' => 't',
                    'target' => 'a',
                ],
                [
                    'id'     => 'e2',
                    'source' => 'a',
                    'target' => 'n',
                ],
            ],
        ];
    }

    public function test_the_index_keeps_steps_and_writes_only_on_change(): void {
        $this->assertSame( 'Acme Rewards', StepIndex::get( 'acme_add_points' )['plugin'] );
        $this->assertSame( [ 'balance' => 'New balance' ], StepIndex::get( 'acme_add_points' )['outputs'] );

        $GLOBALS['yaymatik_test_options'][ StepIndex::OPTION ]['steps']['acme_add_points']['label'] = 'Written';
        self::forget_index();
        StepIndex::refresh( [] );
        $this->assertSame( 'Written', StepIndex::get( 'acme_add_points' )['label'], 'no module: nothing read or written' );
    }

    public function test_a_stored_node_is_kept_with_its_saved_config_and_a_new_one_refused(): void {
        $live  = self::graph()['nodes'];
        $draft = self::graph()['nodes'];
        // The draft swapped `a` for a core step; undoing that brings the live node back.
        $draft[1]['data'] = [
            'block'  => 'add_order_note',
            'config' => [ 'note' => 'x' ],
        ];
        $kept             = GraphSchema::prepare( self::graph( 'Hi', [ 'points' => 20 ] ), array_merge( $live, $draft ) );

        $this->assertSame(
            [
                'block'  => 'acme_add_points',
                'config' => [ 'points' => 10 ],
            ],
            GraphSchema::by_id( $kept['nodes'] )['a']['data'],
            'read-only: the saved config, not the one sent'
        );
        $this->assertSame( 'Unknown step type.', GraphSchema::prepare( self::graph() )->get_error_message() );
        $this->assertTrue( GraphSchema::same( $kept, $kept ), 'a saved graph compares with itself' );
    }

    public function test_only_a_step_whose_module_is_gone_counts_as_inactive(): void {
        $GLOBALS['yaymatik_test_options'][ StepIndex::OPTION ]['steps'] += [
            'send_email'   => [ 'module' => 'woocommerce' ] + StepIndex::get( 'acme_add_points' ),
            'yaymatik_old' => [ 'module' => 'yaymatik' ] + StepIndex::get( 'acme_add_points' ),
        ];
        self::forget_index();

        $this->assertNotNull( StepIndex::inactive( 'acme_add_points' ) );
        $this->assertNull( StepIndex::inactive( 'send_email' ), 'registered' );
        $this->assertNull( StepIndex::inactive( 'yaymatik_old' ), 'its module is active: a dropped step, not a plugin that is off' );
    }

    public function test_data_of_a_module_that_is_off_names_its_plugin_by_the_longest_module_id(): void {
        StepIndex::refresh(
            Extensions::collect(
                [],
                fn( $registrar ) => $registrar->module(
                    [
                        'id'    => 'acme_pro',
                        'label' => 'Acme Pro',
                    ]
                )
            )
        );

        $this->assertSame( 'Acme Rewards', StepIndex::inactive_plugin( 'order.acme_points' ) );
        $this->assertSame( 'Acme Rewards', StepIndex::inactive_plugin( 'acme_member.level' ) );
        $this->assertSame( 'Acme Pro', StepIndex::inactive_plugin( 'order.acme_pro_tier' ), 'not shadowed by acme' );
        $this->assertNull( StepIndex::inactive_plugin( 'order.total' ) );
    }

    public function test_it_saves_but_cannot_be_turned_on_and_its_outputs_stay_readable(): void {
        $graph  = GraphSchema::prepare( self::graph( 'Balance {{ steps.a.balance }}' ), self::graph()['nodes'] );
        $issues = Validator::errors(
            [
                'name'  => 'Points',
                'graph' => $graph,
            ]
        );

        $this->assertSame(
            [
                [
                    'code'    => 'missing_plugin',
                    'message' => Messages::get( 'missingPlugin', 'Acme Rewards' ),
                    'nodeId'  => 'a',
                ],
            ],
            $issues
        );
        $this->assertSame( 'This workflow uses Acme Rewards, which isn’t active. Activate Acme Rewards to turn the workflow on.', $issues[0]['message'] );
        $this->assertSame( [ 'acme_add_points' ], array_keys( array_diff_key( Catalog::inactive_steps(), ProCatalog::STEPS ) ) );
    }

    public function test_health_check_lists_active_workflows_using_it(): void {
        $workflow = fn( int $id, string $status, string $name = '' ) => [
            'id'     => (string) $id,
            'name'   => $name,
            'status' => $status,
            'graph'  => self::graph(),
        ];
        $check    = HealthCheck::missing_plugin( [ $workflow( 3, 'active', 'Points thank-you' ), $workflow( 4, 'inactive' ), $workflow( 5, 'draft' ), $workflow( 6, 'active' ) ] );

        $this->assertSame( [ 'problem', 2, [ 'Acme Rewards' ], null ], [ $check['status'], $check['count'], $check['plugins'], $check['fix'] ] );
        $this->assertSame(
            [
                [
                    'id'   => '3',
                    'name' => 'Points thank-you',
                ],
                [
                    'id'   => '6',
                    'name' => '',
                ],
            ],
            $check['workflows'],
            'the panel names each, "Workflow #6" when it has no name'
        );
    }
}
