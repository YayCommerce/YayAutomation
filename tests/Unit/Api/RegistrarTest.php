<?php
namespace YayMatik\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use YayMatik\Api\Api;
use YayMatik\Api\Module;
use YayMatik\Api\Registrar;
use YayMatik\Workflow\Extension\Diagnostics;
use YayMatik\Workflow\Extension\Extensions;

/** `yaymatik_register`: the registrar, module handles, ids and the API version. */
final class RegistrarTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_options'] = [];
    }

    /** @return string[] `module callback: message`. */
    private static function diagnostics(): array {
        return array_map( fn( array $entry ) => "{$entry['module']} {$entry['callback']}: {$entry['message']}", Diagnostics::recent( 24 ) );
    }

    /**
     * Module ids registered by these module() calls.
     *
     * @param array[] $modules
     */
    private static function registered( array $modules ): array {
        $collected = Extensions::collect(
            [],
            function ( Registrar $registrar ) use ( $modules ) {
                foreach ( $modules as $args ) {
                    $registrar->module( $args );
                }
            }
        );
        return array_map( fn( $module ) => $module->id(), $collected );
    }

    public function test_the_hook_runs_once(): void {
        Extensions::register( [] );

        $this->assertSame( [], Extensions::register( [] ) );
        $this->assertFalse( Extensions::registering() );
    }

    public function test_an_id_core_already_has_is_refused(): void {
        $this->assertSame(
            [],
            Extensions::collect(
                [ 'acme' ],
                fn( Registrar $registrar ) => $registrar->module(
                    [
                        'id'    => 'acme',
                        'label' => 'Acme',
                    ]
                )
            )
        );
        $this->assertSame( [ 'acme module: Module id "acme" is already taken by another module.' ], self::diagnostics() );
    }

    public function test_api_version_and_features(): void {
        Extensions::collect(
            [],
            function ( Registrar $registrar ) {
                $this->assertSame( Api::VERSION, $registrar->api_version() );
                $this->assertTrue( $registrar->supports( 'triggers' ) );
                $this->assertTrue( $registrar->supports( 'actions' ) );
                $this->assertFalse( $registrar->supports( 'email' ) );
            }
        );
    }

    public function test_module_ids_labels_and_reserved_ids(): void {
        $ids = self::registered(
            [
                [
                    'id'    => 'acme',
                    'label' => 'Acme',
                ],
                [
                    'id'    => 'acme',
                    'label' => 'Acme again',
                ],
                [
                    'id'    => 'woocommerce',
                    'label' => 'Woo',
                ],
                [
                    'id'    => 'Bad-Id',
                    'label' => 'Bad',
                ],
                [ 'id' => 'nolabel' ],
            ]
        );

        $this->assertSame( [ 'acme' ], $ids );
        $this->assertEqualsCanonicalizing(
            [
                'acme module: Module id "acme" is already taken by another module.',
                'woocommerce module: "woocommerce" is reserved for YayMatik.',
                'Bad-Id module: A module id is 3–64 lowercase letters, digits or underscores, starting with a letter.',
                'nolabel module: A module needs a label.',
            ],
            self::diagnostics()
        );
    }

    public function test_requires_api_same_major_and_no_newer_minor(): void {
        $ids = self::registered(
            [
                [
                    'id'           => 'one_zero',
                    'label'        => 'A',
                    'requires_api' => '1.0',
                ],
                [
                    'id'           => 'one_zero_zero',
                    'label'        => 'B',
                    'requires_api' => '1.0.0',
                ],
                [
                    'id'           => 'one_one',
                    'label'        => 'C',
                    'requires_api' => '1.1',
                ],
                [
                    'id'           => 'two_zero',
                    'label'        => 'D',
                    'requires_api' => '2.0',
                ],
                [
                    'id'           => 'garbage',
                    'label'        => 'E',
                    'requires_api' => 'latest',
                ],
            ]
        );

        $this->assertSame( [ 'one_zero', 'one_zero_zero' ], $ids );
        $this->assertContains( 'one_one module: Needs YayMatik with Extension API 1.1.', self::diagnostics() );
    }

    public function test_a_refused_module_handle_does_nothing(): void {
        $results = [];
        Extensions::collect(
            [],
            function ( Registrar $registrar ) use ( &$results ) {
                $module    = $registrar->module(
                    [
                        'id'           => 'acme',
                        'label'        => 'Acme',
                        'requires_api' => '9.0',
                    ]
                );
                $results[] = $module instanceof Module;
                $results[] = $module->ok();
                $results[] = $module->trigger( 'acme_points', [] );
                $results[] = $module->action( 'acme_add', [] );
                $results[] = $module->category( 'acme_rewards', 'Rewards' );
            }
        );

        $this->assertSame( [ true, false, false, false, false ], $results );
    }

    public function test_step_ids_start_with_the_module_id(): void {
        $results = [];
        Extensions::collect(
            [],
            function ( Registrar $registrar ) use ( &$results ) {
                $module    = $registrar->module(
                    [
                        'id'    => 'acme',
                        'label' => 'Acme',
                    ]
                );
                $action    = [
                    'label'       => 'Add',
                    'description' => 'Adds.',
                    'run'         => fn( array $config, $run ) => $run->done(),
                ];
                $results[] = $module->action( 'add_points', $action );
                $results[] = $module->action( 'acme_add_points', $action );
                $results[] = $module->action( 'acme_add_points', $action );
                $results[] = $module->category( 'rewards', 'Rewards' );
                $results[] = $module->category( 'acme_rewards', 'Rewards' );
                $results[] = $module->action( 'acme_in_rewards', $action + [ 'category' => 'acme_rewards' ] );
            }
        );

        $this->assertSame( [ false, true, false, false, true, true ], $results );
        $this->assertContains( 'acme action acme_add_points: Step id "acme_add_points" is already declared by this module.', self::diagnostics() );
    }

    public function test_unknown_keys_are_ignored(): void {
        $added = null;
        Extensions::collect(
            [],
            function ( Registrar $registrar ) use ( &$added ) {
                $added = $registrar->module(
                    [
                        'id'     => 'acme',
                        'label'  => 'Acme',
                        'colour' => 'red',
                    ]
                )->action(
                    'acme_add',
                    [
                        'label'       => 'Add',
                        'description' => 'Adds.',
                        'run'         => fn( array $config, $run ) => $run->done(),
                        'retries'     => 3,
                    ]
                );
            }
        );

        $this->assertTrue( $added );
        $this->assertSame( [], self::diagnostics() );
    }

    public function test_calls_after_the_hook_are_refused(): void {
        $kept                       = null;
        $modules                    = Extensions::collect(
            [],
            function ( Registrar $registrar ) use ( &$kept ) {
                $kept = [
                    $registrar,
                    $registrar->module(
                        [
                            'id'    => 'acme',
                            'label' => 'Acme',
                        ]
                    ),
                ];
            }
        );
        list( $registrar, $module ) = $kept;

        $this->assertFalse(
            $module->action(
                'acme_late',
                [
                    'label'       => 'Late',
                    'description' => 'Too late.',
                    'run'         => fn( array $config, $run ) => $run->done(),
                ]
            )
        );
        $this->assertFalse(
            $registrar->module(
                [
                    'id'    => 'other',
                    'label' => 'Other',
                ]
            )->ok()
        );
        $this->assertSame( [], $modules[0]->actions() );
        $this->assertContains( 'acme action acme_late: Called after yaymatik_register has run; register inside its callback.', self::diagnostics() );
    }

    public function test_a_throwing_callback_is_named_by_its_own_module_and_stops_no_other(): void {
        $register = fn( string $id ) => fn( Registrar $registrar ) => $registrar->module(
            [
                'id'    => $id,
                'label' => ucfirst( $id ),
            ]
        );
        $modules  = Extensions::collect(
            [],
            $register( 'first' ),
            function ( Registrar $registrar ) {
                $registrar->module(
                    [
                        'id'    => 'second',
                        'label' => 'Second',
                    ]
                );
                throw new \RuntimeException( 'Boom.' );
            },
            function () {
                throw new \RuntimeException( 'Before any module.' );
            },
            $register( 'third' )
        );

        $this->assertSame( [ 'first', 'second', 'third' ], array_map( fn( $module ) => $module->id(), $modules ) );
        $this->assertEqualsCanonicalizing( [ 'second register: Boom.', '(unknown) register: Before any module.' ], self::diagnostics() );
    }
}
