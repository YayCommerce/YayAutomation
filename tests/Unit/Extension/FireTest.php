<?php
namespace YayMatik\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeQueue;
use YayMatik\Tests\Support\FakeRunRepository;
use YayMatik\Tests\Support\FakeWorkflows;
use YayMatik\Workflow\Core\Runtime\Dispatcher;
use YayMatik\Workflow\Extension\Diagnostics;
use YayMatik\Workflow\Extension\ExtensionTrigger;
use YayMatik\Workflow\Extension\Extensions;
use YayMatik\Workflow\Extension\Fire;

/** `yaymatik_fire`: payload → refs, subject and run, through the core Dispatcher. */
final class FireTest extends TestCase {

    /** @var FakeRunRepository */
    private $runs;

    /** @var FakeWorkflows */
    private $workflows;

    /** @var Dispatcher */
    private $dispatcher;

    /** @var ExtensionTrigger[] By id. */
    private $triggers = [];

    /** @var Fire */
    private $fire;

    /** @var int Calls to the step lookup. */
    private $lookups = 0;

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_options'] = [];
        $this->runs                       = new FakeRunRepository();
        $this->workflows                  = new FakeWorkflows();
        $this->dispatcher                 = new Dispatcher(
            $this->runs,
            new FakeQueue(),
            $this->workflows,
            function () {},
            fn() => null,
            fn() => []
        );
        $module                           = Extensions::collect(
            [],
            function ( $registrar ) {
                $module = $registrar->module(
                    [
                        'id'    => 'acme',
                        'label' => 'Acme',
                    ]
                );
                $module->trigger(
                    'acme_points',
                    [
                        'label'       => 'Points earned',
                        'description' => 'When points are earned.',
                        'provides'    => [ 'customer' ],
                        'settings'    => [
                            'min' => [
                                'type'    => 'number',
                                'label'   => 'At least',
                                'default' => 1,
                            ],
                        ],
                        'matches'     => fn( array $payload, array $config ) => $payload['user_id'] >= $config['min'],
                    ]
                );
                $module->data_item(
                    'acme_reward',
                    [
                        'label'       => 'Reward',
                        'description' => 'A redeemed reward.',
                        'load'        => fn( string $id ) => (object) [ 'id' => $id ],
                    ]
                );
                $module->trigger(
                    'acme_redeemed',
                    [
                        'label'        => 'Reward redeemed',
                        'description'  => 'When a reward is redeemed.',
                        'provides'     => [ 'acme_reward' ],
                        'facts'        => [
                            'points' => [
                                'label' => 'Points',
                                'type'  => 'number',
                            ],
                            'note'   => [
                                'label' => 'Note',
                                'type'  => 'string',
                            ],
                        ],
                        'sample_facts' => [ 'points' => 25 ],
                    ]
                );
                $module->trigger(
                    'acme_order',
                    [
                        'label'       => 'Order rewarded',
                        'description' => 'When an order earns points.',
                        'provides'    => [ 'order', 'customer' ],
                        'once_per'    => 'subject',
                    ]
                );
            }
        )[0];
        foreach ( $module->triggers() as $trigger ) {
            $this->triggers[ $trigger->id() ] = $trigger;
        }
        $this->fire = new Fire(
            $this->workflows,
            $this->dispatcher,
            function ( string $id ) {
                ++$this->lookups;
                return $this->triggers[ $id ] ?? null;
            }
        );
        $this->add_workflow( 1, 'acme_points' );
        $this->add_workflow( 2, 'acme_order' );
        $this->add_workflow( 4, 'acme_redeemed' );
    }

    /**
     * @param int    $id
     * @param string $trigger
     * @param array  $config
     */
    private function add_workflow( int $id, string $trigger, array $config = [] ): void {
        $graph                             = [
            'nodes' => [
                [
                    'id'   => 't',
                    'kind' => 'trigger',
                    'data' => [
                        'block'  => $trigger,
                        'config' => $this->triggers[ $trigger ]->sanitize( $config ),
                    ],
                ],
            ],
            'edges' => [],
        ];
        $this->workflows->workflows[ $id ] = [
            'id'     => (string) $id,
            'status' => 'active',
            'graph'  => $graph,
        ];
        $this->workflows->versions[ $id ]  = 10 + $id;
    }

    /**
     * @param string $trigger
     * @param mixed  $payload
     */
    private function fire( string $trigger, $payload ): void {
        $this->fire->handle( $trigger, $payload );
        $this->dispatcher->flush();
    }

    /**
     * A run's trigger scope as stored.
     *
     * @param int $run
     */
    private function scope( int $run ): array {
        return $this->runs->runs[ $run ]['scope']['trigger'];
    }

    private static function ref( string $type, string $id ): array {
        return [
            '__ref' => [
                'type' => $type,
                'id'   => $id,
            ],
        ];
    }

    /** @return string[] Diagnostic messages. */
    private static function diagnostics(): array {
        return array_column( Diagnostics::recent( 24 ), 'message' );
    }

    public function test_user_id_becomes_the_customer_and_the_subject(): void {
        $this->fire( 'acme_points', [ 'user_id' => 5 ] );

        $this->assertCount( 1, $this->runs->runs );
        $this->assertSame( 'user:5', $this->runs->runs[1]['subject'] );
        $this->assertSame( 'acme_points', $this->runs->runs[1]['trigger_id'] );
        $this->assertSame( [ 'customer' => self::ref( 'customer', 'user:5' ) ], $this->scope( 1 ) );
    }

    public function test_order_id_gives_the_order_and_its_customer(): void {
        $this->fire( 'acme_order', [ 'order_id' => '12' ] );

        $this->assertSame( 'order:12', $this->runs->runs[1]['subject'] );
        $this->assertSame(
            [
                'order'    => self::ref( 'order', '12' ),
                'customer' => self::ref( 'customer', 'order:12' ),
            ],
            $this->scope( 1 )
        );
    }

    public function test_a_user_id_beside_an_order_id_does_not_change_the_customer(): void {
        $this->fire(
            'acme_order',
            [
                'user_id'  => 5,
                'order_id' => 12,
            ]
        );

        $this->assertSame( 'order:12', $this->runs->runs[1]['subject'] );
        $this->assertSame( self::ref( 'customer', 'order:12' ), $this->scope( 1 )['customer'] );
        $this->assertSame( 5, $this->scope( 1 )['user_id'], 'kept for Step::user() on a guest order' );
    }

    public function test_a_user_id_beside_a_cart_id_does_not_change_the_customer(): void {
        $this->fire(
            'acme_points',
            [
                'user_id' => 5,
                'cart_id' => 9,
            ]
        );

        $this->assertSame( 'cart:9', $this->runs->runs[1]['subject'] );
        $this->assertSame(
            [
                'cart'     => self::ref( 'cart', '9' ),
                'user_id'  => 5,
                'customer' => self::ref( 'customer', 'cart:9' ),
            ],
            $this->scope( 1 )
        );
    }

    public function test_subject_is_the_first_of_order_cart_review_user(): void {
        $cases = [
            'order:3'  => [
                'user_id'   => 1,
                'review_id' => 2,
                'order_id'  => 3,
                'cart_id'   => 4,
            ],
            'cart:4'   => [
                'user_id'   => 1,
                'review_id' => 2,
                'cart_id'   => 4,
            ],
            'review:2' => [
                'user_id'   => 1,
                'review_id' => 2,
            ],
            'user:1'   => [ 'user_id' => 1 ],
        ];
        foreach ( $cases as $subject => $payload ) {
            $this->runs = new FakeRunRepository();
            $this->setUp();
            $this->fire( 'acme_points', $payload );
            $this->assertSame( $subject, $this->runs->runs[1]['subject'] );
            $this->assertSame( $subject, $this->scope( 1 )['customer']['__ref']['id'] );
        }
    }

    public function test_unknown_keys_and_bad_ids_are_dropped_with_a_diagnostic(): void {
        $this->fire(
            'acme_points',
            [
                'user_id' => 5,
                'points'  => 25,
                'cart_id' => 'abc',
            ]
        );

        $this->assertSame( [ 'customer' => self::ref( 'customer', 'user:5' ) ], $this->scope( 1 ) );
        $entry = Diagnostics::recent( 24 )[0];
        $this->assertSame(
            [ 'acme', 'payload acme_points', 'Payload key "points" is not an id or a declared fact; it was dropped.', 2 ],
            [ $entry['module'], $entry['callback'], $entry['message'], $entry['count'] ],
            'the bad id counts under the same callback'
        );
    }

    public function test_a_payload_missing_what_provides_needs_starts_no_run(): void {
        $this->fire( 'acme_order', [ 'user_id' => 5 ] );

        $this->assertSame( [], $this->runs->runs );
        $this->assertSame( [ 'The payload gives no "order", which this trigger provides; no run was started.' ], self::diagnostics() );
    }

    public function test_a_refused_run_shows_beside_a_dropped_key(): void {
        $this->fire( 'acme_order', [ 'junk' => 1 ] );

        $this->assertEqualsCanonicalizing(
            [
                'payload acme_order' => 'Payload key "junk" is not an id or a declared fact; it was dropped.',
                'fire acme_order'    => 'The payload gives no "order", which this trigger provides; no run was started.',
            ],
            array_column( Diagnostics::recent( 24 ), 'message', 'callback' )
        );
    }

    public function test_before_init_the_event_waits_for_init(): void {
        $GLOBALS['yaymatik_test_did_action']['init'] = 0;
        $this->fire( 'acme_points', [ 'user_id' => 5 ] );
        $this->assertSame( [], $this->runs->runs );
        $this->assertSame( 0, $this->lookups, 'the registry is not built before init' );

        $GLOBALS['yaymatik_test_did_action']['init'] = 1;
        do_action( 'init' );
        $this->dispatcher->flush();
        unset( $GLOBALS['yaymatik_test_did_action'], $GLOBALS['yaymatik_test_filters']['init'] );

        $this->assertSame( 'user:5', $this->runs->runs[1]['subject'] );
    }

    public function test_no_active_workflow_returns_before_the_registry(): void {
        $this->workflows->workflows[1]['status'] = 'inactive';
        $this->fire( 'acme_points', [ 'user_id' => 5 ] );
        $this->fire( 'acme_unknown', [ 'user_id' => 5 ] );

        $this->assertSame( [], $this->runs->runs );
        $this->assertSame( 0, $this->lookups );
        $this->assertSame( 0, $this->workflows->lookups );
    }

    public function test_bad_arguments_and_core_triggers_are_ignored(): void {
        $this->fire->handle( [ 'acme_points' ], [ 'user_id' => 5 ] );
        $this->fire->handle( 'acme_points', 'user_id=5' );
        $this->triggers['acme_points'] = new \YayMatik\Tests\Support\FakeTrigger();
        $this->fire( 'acme_points', [ 'user_id' => 5 ] );

        $this->assertSame( [], $this->runs->runs );
    }

    public function test_matches_gets_the_payload_and_the_workflow_config(): void {
        $this->add_workflow( 3, 'acme_points', [ 'min' => 10 ] );
        $this->fire( 'acme_points', [ 'user_id' => 5 ] );
        $this->fire( 'acme_points', [ 'user_id' => 15 ] );

        $this->assertSame( [ 1, 1, 3 ], array_values( array_column( $this->runs->runs, 'workflow_id' ) ) );
    }

    public function test_once_per_subject_claims_per_workflow(): void {
        $this->fire( 'acme_order', [ 'order_id' => 12 ] );
        $this->fire( 'acme_order', [ 'order_id' => 12 ] );
        $this->fire( 'acme_order', [ 'order_id' => 13 ] );

        $this->assertSame( [ 'order:12', 'order:13' ], array_values( array_column( $this->runs->runs, 'subject' ) ) );
    }

    public function test_a_throwing_matches_is_no_match_and_a_diagnostic(): void {
        $this->triggers['acme_points'] = Extensions::collect(
            [],
            function ( $registrar ) {
                $registrar->module(
                    [
                        'id'    => 'acme',
                        'label' => 'Acme',
                    ]
                )->trigger(
                    'acme_points',
                    [
                        'label'       => 'Points earned',
                        'description' => 'When points are earned.',
                        'provides'    => [ 'customer' ],
                        'matches'     => function () {
                            throw new \RuntimeException( 'Boom.' );
                        },
                    ]
                );
            }
        )[0]->triggers()[0];
        $this->fire( 'acme_points', [ 'user_id' => 5 ] );

        $this->assertSame( [], $this->runs->runs );
        $this->assertSame( [ 'Boom.' ], self::diagnostics() );
    }

    public function test_a_module_data_item_is_the_subject_when_nothing_else_is(): void {
        $this->fire( 'acme_redeemed', [ 'acme_reward_id' => 7 ] );
        $this->fire(
            'acme_redeemed',
            [
                'acme_reward_id' => 8,
                'user_id'        => 5,
            ]
        );

        $this->assertSame( [ 'acme_reward:7', 'user:5' ], array_values( array_column( $this->runs->runs, 'subject' ) ) );
        $this->assertSame( [ 'acme_reward' => self::ref( 'acme_reward', '7' ) ], $this->scope( 1 ) );
        $this->assertSame( self::ref( 'customer', 'user:5' ), $this->scope( 2 )['customer'] );
    }

    public function test_declared_facts_are_kept_as_plain_values(): void {
        $this->fire(
            'acme_redeemed',
            [
                'acme_reward_id' => 7,
                'points'         => 25,
                'note'           => 'Thanks!',
            ]
        );

        $this->assertSame(
            [
                'acme_reward' => self::ref( 'acme_reward', '7' ),
                'points'      => 25,
                'note'        => 'Thanks!',
            ],
            $this->scope( 1 )
        );
        $this->assertSame( [ 'points' => 25 ], $this->triggers['acme_redeemed']->sample_facts() );
        $this->assertSame( [], self::diagnostics() );
    }

    public function test_a_fact_that_is_not_one_value_is_dropped_and_a_long_one_cut(): void {
        $this->fire(
            'acme_redeemed',
            [
                'acme_reward_id' => 7,
                'points'         => [ 1, 2 ],
                'note'           => str_repeat( 'é', 600 ),
            ]
        );

        $scope = $this->scope( 1 );
        $this->assertArrayNotHasKey( 'points', $scope );
        $this->assertSame( 1024, strlen( $scope['note'] ) );
        $this->assertSame( 2, Diagnostics::recent( 24 )[0]['count'] );
        $this->assertSame( 'Fact "points" is not a single value; it was dropped.', Diagnostics::recent( 24 )[0]['message'] );
    }

    public function test_a_data_item_declared_after_the_trigger_still_maps_its_payload_key(): void {
        $module = Extensions::collect(
            [],
            function ( $registrar ) {
                $module = $registrar->module(
                    [
                        'id'    => 'late',
                        'label' => 'Late',
                    ]
                );
                $module->trigger(
                    'late_done',
                    [
                        'label'       => 'Done',
                        'description' => 'Done.',
                        'provides'    => [ 'customer' ],
                    ]
                );
                $module->data_item(
                    'late_thing',
                    [
                        'label'       => 'Thing',
                        'description' => 'A thing.',
                        'load'        => fn() => null,
                    ]
                );
            }
        )[0];

        $this->assertSame(
            [
                'user_id'       => 5,
                'late_thing_id' => 3,
            ],
            $module->triggers()[0]->args(
                [
                    'user_id'       => 5,
                    'late_thing_id' => 3,
                ]
            )
        );
    }
}
