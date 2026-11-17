<?php
namespace YayMatik\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Extension\Diagnostics;
use YayMatik\Workflow\Extension\ExtensionModule;
use YayMatik\Workflow\Extension\Extensions;
use YayMatik\Workflow\Extension\StepIndex;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** Which extension templates Browse templates hides (docs/extension-api.md §7), checked when the index changes. */
final class TemplateCheckTest extends TestCase {

    /** @var \ReflectionProperty */
    private $steps;

    /** @var array Core steps, put back after. */
    private $core;

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_options'] = [];
        self::forget_index();
        $this->steps = new \ReflectionProperty( StepRegistry::class, 'steps' );
        $this->steps->setAccessible( true );
        $this->core = StepRegistry::all();
    }

    protected function tearDown(): void {
        $this->steps->setValue( null, $this->core );
        self::forget_index();
    }

    private static function forget_index(): void {
        $stored = new \ReflectionProperty( StepIndex::class, 'stored' );
        $stored->setAccessible( true );
        $stored->setValue( null, null );
    }

    /**
     * Acme's module with these templates, its steps registered beside core's.
     *
     * @param array $templates       Id => steps.
     * @param bool  $points_required Whether Add points needs its points.
     */
    private function acme( array $templates, bool $points_required = true ): ExtensionModule {
        $modules = Extensions::collect(
            [],
            function ( $registrar ) use ( $templates, $points_required ) {
                $other = $registrar->module(
                    [
                        'id'    => 'other',
                        'label' => 'Other',
                    ]
                );
                $other->action(
                    'other_ping',
                    [
                        'label'       => 'Ping',
                        'description' => 'Pings.',
                        'run'         => fn( array $config, $run ) => $run->done(),
                    ]
                );
                $acme = $registrar->module(
                    [
                        'id'    => 'acme',
                        'label' => 'Acme',
                    ]
                );
                $acme->action(
                    'acme_add_points',
                    [
                        'label'       => 'Add points',
                        'description' => 'Adds points.',
                        'fields'      => [
                            'points' => [
                                'type'     => 'number',
                                'label'    => 'Points',
                                'min'      => 1,
                                'required' => $points_required,
                            ],
                        ],
                        'outputs'     => [
                            'balance' => [
                                'label' => 'Balance',
                                'type'  => 'number',
                            ],
                        ],
                        'run'         => fn( array $config, $run ) => $run->done(),
                    ]
                );
                $acme->action(
                    'acme_mail',
                    [
                        'label'       => 'Send rewards email',
                        'description' => 'Sends.',
                        'fields'      => [
                            'subject'   => [
                                'type'      => 'text',
                                'label'     => 'Subject',
                                'variables' => true,
                                'required'  => true,
                            ],
                            'design_id' => [
                                'type'     => 'text',
                                'label'    => 'Design',
                                'required' => true,
                            ],
                        ],
                        'setup_issue' => fn() => 'Create a design in Acme first.',
                        'email'       => [
                            'recipient' => 'customer',
                            'build'     => fn() => [],
                        ],
                    ]
                );
                foreach ( $templates as $id => $steps ) {
                    $acme->template(
                        $id,
                        [
                            'name'        => $id,
                            'description' => 'D.',
                            'category'    => 'customer_lifecycle',
                            'steps'       => $steps,
                        ]
                    );
                }
            }
        );
        $all     = $this->core;
        foreach ( $modules as $module ) {
            foreach ( $module->actions() as $step ) {
                $all[ $step->id() ] = $step;
            }
        }
        $this->steps->setValue( null, $all );
        return $modules[1];
    }

    /**
     * A chain: account created, then these steps.
     *
     * @param array ...$steps
     */
    private static function chain( array ...$steps ): array {
        return array_merge(
            [
                [
                    'id'   => 'trigger',
                    'step' => 'customer_account_created',
                ],
            ],
            $steps
        );
    }

    /**
     * @param string $id
     * @param string $step
     * @param array  $config
     */
    private static function step( string $id, string $step, array $config = [] ): array {
        return [
            'id'     => $id,
            'step'   => $step,
            'config' => $config,
        ];
    }

    public function test_setup_left_for_the_store_owner_keeps_a_template_a_broken_one_is_hidden(): void {
        $points = self::step( 'bonus', 'acme_add_points', [ 'points' => 100 ] );
        $acme   = $this->acme(
            [
                'acme_ready'       => self::chain(
                    $points,
                    self::step(
                        'email',
                        'acme_mail',
                        [
                            'subject'   => 'You have {{ steps.bonus.balance }} points',
                            'design_id' => '',
                        ]
                    )
                ),
                'acme_unknown_tag' => self::chain(
                    self::step(
                        'email',
                        'acme_mail',
                        [
                            'subject'   => '{{ customer.nope }}',
                            'design_id' => 'd1',
                        ]
                    )
                ),
                'acme_no_action'   => self::chain(),
                'acme_bad_value'   => self::chain( self::step( 'bonus', 'acme_add_points', [ 'points' => -5 ] ) ),
                'acme_foreign'     => self::chain( self::step( 'ping', 'other_ping' ) ),
                'acme_bad_chain'   => self::chain( self::step( 'ghost', 'acme_nope' ) ),
            ]
        );

        StepIndex::refresh( [ $acme ] );
        $hidden = StepIndex::hidden_templates();

        $this->assertSame( [ 'acme_unknown_tag', 'acme_no_action', 'acme_bad_value', 'acme_foreign', 'acme_bad_chain' ], array_keys( $hidden ), 'blank design and setup_issue: kept' );
        $this->assertStringStartsWith( 'Step "email": ', $hidden['acme_unknown_tag'] );
        $this->assertStringContainsString( 'another plugin', $hidden['acme_foreign'] );
        $this->assertSame( 'Step "ghost": unknown step "acme_nope".', $hidden['acme_bad_chain'] );
        $this->assertContains( 'Hidden from Browse templates: Step "ghost": unknown step "acme_nope".', array_column( Diagnostics::recent( 24 ), 'message' ) );
    }

    public function test_a_template_using_a_pro_step_or_field_is_hidden_in_the_free_edition_only(): void {
        $acme = $this->acme(
            [
                'acme_pro_step'  => self::chain(
                    self::step(
                        'wait',
                        'wait_until',
                        [
                            'time'     => '09:00',
                            'weekdays' => [],
                        ]
                    ),
                    self::step( 'bonus', 'acme_add_points', [ 'points' => 100 ] )
                ),
                'acme_pro_field' => self::chain(
                    self::step(
                        'email',
                        'acme_mail',
                        [
                            'subject'   => 'You spent {{ customer.total_spent }}',
                            'design_id' => 'd1',
                        ]
                    )
                ),
            ]
        );

        StepIndex::refresh( [ $acme ] );

        $this->assertSame( YAYMATIK_TEST_LITE ? [ 'acme_pro_step', 'acme_pro_field' ] : [], array_keys( StepIndex::hidden_templates() ) );
    }

    public function test_the_check_runs_again_only_when_the_steps_or_templates_change(): void {
        $acme = $this->acme( [ 'acme_no_action' => self::chain() ] );
        StepIndex::refresh( [ $acme ] );
        $this->assertSame( [ 'acme_no_action' ], array_keys( StepIndex::hidden_templates() ) );

        // The stored list is what pages read: an unchanged registry doesn't check again.
        $GLOBALS['yaymatik_test_options'][ StepIndex::OPTION ]['templates'] = [];
        self::forget_index();
        StepIndex::refresh( [ $acme ] );
        $this->assertSame( [], StepIndex::hidden_templates() );

        $changed = $this->acme(
            [
                'acme_no_action' => self::chain(),
                'acme_new'       => self::chain(),
            ]
        );
        StepIndex::refresh( [ $changed ] );
        $this->assertSame( [ 'acme_no_action', 'acme_new' ], array_keys( StepIndex::hidden_templates() ) );

        // Same keys, another value: a fixed template is offered again.
        $fixed = $this->acme(
            [
                'acme_no_action' => self::chain( self::step( 'bonus', 'acme_add_points', [ 'points' => 5 ] ) ),
                'acme_new'       => self::chain( self::step( 'bonus', 'acme_add_points', [ 'points' => -5 ] ) ),
            ]
        );
        StepIndex::refresh( [ $fixed ] );
        $before = StepIndex::hidden_templates();
        $fixed  = $this->acme(
            [
                'acme_no_action' => self::chain( self::step( 'bonus', 'acme_add_points', [ 'points' => 5 ] ) ),
                'acme_new'       => self::chain( self::step( 'bonus', 'acme_add_points', [ 'points' => 7 ] ) ),
            ]
        );
        StepIndex::refresh( [ $fixed ] );
        $this->assertSame( [ 'acme_new' ], array_keys( $before ) );
        $this->assertSame( [], StepIndex::hidden_templates() );
    }

    public function test_a_templates_workflow_starts_with_its_module_id(): void {
        $refused = Extensions::collect(
            [],
            function ( $registrar ) {
                $registrar->module(
                    [
                        'id'    => 'acme',
                        'label' => 'Acme',
                    ]
                )->template(
                    'acme_cart',
                    [
                        'name'        => 'Cart',
                        'description' => 'D.',
                        'category'    => 'recover_carts',
                        'workflow'    => 'abandoned_cart',
                        'steps'       => self::chain(),
                    ]
                );
            }
        )[0];

        $this->assertSame( [], $refused->templates(), 'core\'s "abandoned_cart" would list it among core\'s versions' );
    }

    public function test_a_changed_field_rule_checks_the_templates_again(): void {
        $templates = [ 'acme_blank_points' => self::chain( self::step( 'bonus', 'acme_add_points', [ 'points' => '' ] ) ) ];
        StepIndex::refresh( [ $this->acme( $templates, false ) ] );
        $this->assertSame( [], StepIndex::hidden_templates(), 'blank and optional: nothing wrong' );

        StepIndex::refresh( [ $this->acme( $templates, true ) ] );
        $this->assertSame( [], StepIndex::hidden_templates(), 'blank and now required: needs setup, still shown' );
        $this->assertNotSame( '', get_option( StepIndex::OPTION )['checked'] );
        $checked = get_option( StepIndex::OPTION )['checked'];
        StepIndex::refresh( [ $this->acme( $templates, false ) ] );
        $this->assertNotSame( $checked, get_option( StepIndex::OPTION )['checked'], 'the field\'s rule is part of what the check read' );
    }
}
