<?php
namespace YayMatik\Tests\Unit\Core\Definition;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Definition\GraphSchema;
use YayMatik\Workflow\Core\Definition\Messages;
use YayMatik\Workflow\Core\Definition\Validator;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Core\Storage\Settings;
use YayMatik\Workflow\Extension\Extensions;

require_once dirname( __DIR__, 3 ) . '/Support/woocommerce-stubs.php';

/** Workflow issues (`errors()`): codes, messages and the step they point at. */
final class ValidatorTest extends TestCase {

    /**
     * @param string      $id
     * @param string      $kind
     * @param string|null $block  Step id.
     * @param array       $config
     */
    private static function node( string $id, string $kind, ?string $block, array $config = [] ): array {
        return [
            'id'       => $id,
            'kind'     => $kind,
            'position' => [
                'x' => 0,
                'y' => 0,
            ],
            'data'     => [
                'block'  => $block,
                'config' => $config,
            ],
        ];
    }

    /**
     * @param string      $source
     * @param string      $target
     * @param string|null $handle Branch.
     */
    private static function edge( string $source, string $target, ?string $handle = null ): array {
        $edge = [
            'id'     => "$source-$target",
            'source' => $source,
            'target' => $target,
        ];
        if ( null !== $handle ) {
            $edge['sourceHandle'] = $handle;
        }
        return $edge;
    }

    /**
     * Issues of a workflow, its graph prepared as a save would.
     *
     * @param array  $nodes
     * @param array  $edges
     * @param string $name
     */
    private static function issues( array $nodes, array $edges, string $name = 'Alert' ): array {
        $graph = GraphSchema::prepare(
            [
                'nodes' => $nodes,
                'edges' => $edges,
            ]
        );
        return Validator::errors(
            [
                'name'  => $name,
                'graph' => $graph,
            ]
        );
    }

    private static function note( string $text, string $id = 'note' ): array {
        return self::node( $id, 'action', 'add_order_note', [ 'note' => $text ] );
    }

    private static function trigger(): array {
        return self::node( 't', 'trigger', 'order_created' );
    }

    public function test_a_complete_workflow_has_no_issues(): void {
        $this->assertSame(
            [],
            self::issues(
                [
                    self::trigger(),
                    self::node(
                        'c',
                        'condition',
                        'condition',
                        [
                            'rules' => [
                                [
                                    'field'    => 'order.total',
                                    'operator' => 'greater_or_equal',
                                    'value'    => '10',
                                ],
                            ],
                        ]
                    ),
                    self::node(
                        'email',
                        'action',
                        'send_internal_email',
                        [
                            'subject' => 'Order {{ order.number }}',
                            'message' => 'From {{ customer.email }} on {{ site.name }}',
                        ]
                    ),
                    self::note( 'Total {{ order.total }}' ),
                ],
                [ self::edge( 't', 'c' ), self::edge( 'c', 'email', 'then' ), self::edge( 'email', 'note' ) ]
            )
        );
    }

    /**
     * A prepared cart workflow, with this status when not null.
     *
     * @param string|null $status
     */
    private static function cart_workflow( ?string $status ): array {
        $workflow = [
            'name'  => 'Reminder',
            'graph' => GraphSchema::prepare(
                [
                    'nodes' => [
                        self::node( 't', 'trigger', 'cart_abandoned' ),
                        self::node(
                            'n',
                            'action',
                            'send_internal_email',
                            [
                                'recipients' => 'a@x.com',
                                'subject'    => 'Hi',
                                'message'    => 'Hi',
                            ]
                        ),
                    ],
                    'edges' => [ self::edge( 't', 'n' ) ],
                ]
            ),
        ];
        return null === $status ? $workflow : $workflow + [ 'status' => $status ];
    }

    /**
     * Issue codes with cart tracking on or off.
     *
     * @param array $workflow
     * @param bool  $tracking
     */
    private static function codes_with_tracking( array $workflow, bool $tracking ): array {
        update_option( 'yaymatik_settings', [ 'cart_tracking' => $tracking ] );
        $built = new \ReflectionProperty( Settings::class, 'all' );
        $built->setAccessible( true );
        $built->setValue( null, null );
        $issues = Validator::errors( $workflow );
        unset( $GLOBALS['yaymatik_test_options']['yaymatik_settings'] );
        $built->setValue( null, null );
        return array_column( $issues, 'code', 'nodeId' );
    }

    public function test_tracking_off_blocks_activating_a_cart_workflow(): void {
        $this->assertSame( [ 't' => 'cart_tracking_off' ], self::codes_with_tracking( self::cart_workflow( 'inactive' ), false ) );
    }

    public function test_tracking_on_has_no_setup_issue(): void {
        $this->assertSame( [], self::codes_with_tracking( self::cart_workflow( 'inactive' ), true ) );
    }

    public function test_an_active_cart_workflow_has_no_setup_issue(): void {
        $this->assertSame( [], self::codes_with_tracking( self::cart_workflow( 'active' ), false ) );
    }

    public function test_no_status_means_no_setup_issue_so_apply_and_preview_go_on(): void {
        $this->assertSame( [], self::codes_with_tracking( self::cart_workflow( null ), false ) );
    }

    /**
     * The same steps under another trigger, as a live graph.
     *
     * @param string $trigger
     */
    private static function live_graph( string $trigger ): array {
        $graph                              = self::cart_workflow( null )['graph'];
        $graph['nodes'][0]['data']['block'] = $trigger;
        return $graph;
    }

    public function test_a_draft_keeping_the_cart_trigger_has_no_setup_issue(): void {
        $workflow = [ 'live_graph' => self::live_graph( 'cart_abandoned' ) ] + self::cart_workflow( 'active' );

        $this->assertSame( [], self::codes_with_tracking( $workflow, false ) );
    }

    public function test_a_draft_changing_to_a_cart_trigger_with_tracking_off_has_the_setup_issue(): void {
        $workflow = [ 'live_graph' => self::live_graph( 'order_created' ) ] + self::cart_workflow( 'active' );

        $this->assertSame( [ 't' => 'cart_tracking_off' ], self::codes_with_tracking( $workflow, false ) );
        $this->assertSame( [], self::codes_with_tracking( $workflow, true ) );
    }

    public function test_apply_without_a_status_checks_only_a_changed_trigger(): void {
        $same    = [ 'live_graph' => self::live_graph( 'cart_abandoned' ) ] + self::cart_workflow( null );
        $changed = [ 'live_graph' => self::live_graph( 'order_created' ) ] + self::cart_workflow( null );

        $this->assertSame( [], self::codes_with_tracking( $same, false ) );
        $this->assertSame( [ 't' => 'cart_tracking_off' ], self::codes_with_tracking( $changed, false ) );
    }

    public function test_workflow_level_issues_come_first(): void {
        $this->assertSame(
            [
                [
                    'code'    => 'name_required',
                    'message' => 'Give this workflow a name.',
                ],
                [
                    'code'    => 'no_trigger',
                    'message' => 'Choose a trigger.',
                ],
                [
                    'code'    => 'no_action',
                    'message' => 'Add at least one action.',
                ],
            ],
            self::issues( [ self::node( 't', 'trigger', null ) ], [], '' )
        );
    }

    public function test_unchosen_action_and_unlinked_steps(): void {
        $this->assertSame(
            [
                [
                    'code'    => 'unlinked',
                    'nodeIds' => [ 'lost' ],
                    'message' => 'Some steps aren’t connected. Connect them or remove them.',
                ],
                [
                    'code'    => 'no_block',
                    'message' => 'Choose an action.',
                    'nodeId'  => 'a',
                ],
                [
                    'code'    => 'invalid_config',
                    'message' => 'Add order note needs a note.',
                    'field'   => 'note',
                    'nodeId'  => 'lost',
                ],
            ],
            self::issues(
                [ self::trigger(), self::node( 'a', 'action', null ), self::note( '', 'lost' ) ],
                [ self::edge( 't', 'a' ) ]
            )
        );
    }

    public function test_step_settings_issues(): void {
        $issues = self::issues(
            [
                self::node( 't', 'trigger', 'order_status_changed', [ 'to' => [ 'gone' ] ] ),
                self::node( 'c', 'condition', 'condition', [ 'rules' => [] ] ),
                self::note( 'Hi {{ order.foo }}' ),
                self::node( 'meta', 'action', 'update_order_meta', [ 'key' => '' ] ),
            ],
            [ self::edge( 't', 'c' ), self::edge( 'c', 'note', 'then' ), self::edge( 'c', 'meta', 'otherwise' ) ]
        );

        $this->assertSame(
            [
                [ 'invalid_config', 'Choose options from the list.', 't' ],
                [ 'invalid_config', 'Add a rule, or remove this condition.', 'c' ],
                [ 'invalid_config', '{{ order.foo }} isn’t an available variable.', 'note' ],
                [ 'invalid_config', 'Update order custom field needs a field name.', 'meta' ],
            ],
            array_map( fn( array $issue ) => [ $issue['code'], $issue['message'], $issue['nodeId'] ], $issues )
        );
        $this->assertSame( [ 'to', 'rules', 'note', 'key' ], array_column( $issues, 'field' ), 'each step issue names its field' );
        $this->assertArrayNotHasKey( 'rule', $issues[1], 'a condition with no rule names none' );
    }

    public function test_a_condition_rule_issue_names_its_rule(): void {
        $rules = [
            [
                'field'    => 'order.total',
                'operator' => 'greater_than',
                'value'    => '1',
            ],
            [
                'match' => 'any',
                'rules' => [
                    [
                        'field'    => 'order.meta',
                        'key'      => '_order_total',
                        'operator' => 'is_empty',
                        'value'    => '',
                    ],
                ],
            ],
        ];
        $issue = self::issue_of( 'c', [], $rules );
        $this->assertSame( 'This custom field is protected. Choose another one.', $issue['message'] );
        $this->assertSame( 'rules', $issue['field'] );
        $this->assertSame( [ 1, 0 ], $issue['rule'] );
    }

    /**
     * The one issue of step `$id` in: trigger → condition c → (then) email → note; (otherwise) meta.
     * Texts go into the email's subject, the note and the meta value.
     *
     * @param string $id      Step whose issue to return.
     * @param array  $texts   [ 'email' => subject, 'note' => note, 'meta' => value ].
     * @param array  $rules   Condition rules.
     * @param string $note_id Id of the note step.
     */
    private static function issue_of( string $id, array $texts, array $rules = [], string $note_id = 'note' ): ?array {
        $rules  = $rules ? $rules : [
            [
                'field'    => 'order.total',
                'operator' => 'greater_than',
                'value'    => '1',
            ],
        ];
        $issues = self::issues(
            [
                self::trigger(),
                self::node( 'c', 'condition', 'condition', [ 'rules' => $rules ] ),
                self::node(
                    'email',
                    'action',
                    'send_internal_email',
                    [
                        'subject' => $texts['email'] ?? 's',
                        'message' => 'm',
                    ]
                ),
                self::note( $texts['note'] ?? 'n', $note_id ),
                self::node(
                    'meta',
                    'action',
                    'update_order_meta',
                    [
                        'key'   => 'k',
                        'value' => $texts['meta'] ?? '',
                    ]
                ),
            ],
            [ self::edge( 't', 'c' ), self::edge( 'c', 'email', 'then' ), self::edge( 'email', $note_id ), self::edge( 'c', 'meta', 'otherwise' ) ]
        );
        foreach ( $issues as $issue ) {
            if ( ( $issue['nodeId'] ?? null ) === $id ) {
                unset( $issue['nodeId'] );
                return $issue;
            }
        }
        return null;
    }

    private static function expect( string $code, string $message, string $field ): array {
        return [
            'code'    => $code,
            'message' => $message,
            'field'   => $field,
        ];
    }

    public function test_unknown_merge_tags_keep_their_message_and_name_the_field(): void {
        $this->assertSame( self::expect( 'invalid_config', '{{ order.foo }} isn’t an available variable.', 'subject' ), self::issue_of( 'email', [ 'email' => 'Hi {{ order.foo }}' ] ) );
        $this->assertSame( self::expect( 'invalid_config', '{{ customer.bar }} isn’t an available variable.', 'note' ), self::issue_of( 'note', [ 'note' => '{{ customer.bar }}' ] ) );
        $this->assertSame( self::expect( 'invalid_config', '{{ order.foo }} isn’t an available variable.', 'value' ), self::issue_of( 'meta', [ 'meta' => '{{ order.foo }}' ] ) );
    }

    public function test_deep_paths_resolve_through_the_catalog(): void {
        foreach ( [ '{{ order.customer.first_name }}', '{{ order.items }}', '{{ trigger.order.total }}', '{{ customer.email }}', '{{ site.name }}' ] as $tag ) {
            $this->assertNull( self::issue_of( 'note', [ 'note' => $tag ] ), $tag );
        }
        foreach ( [ 'order.customer.nope', 'order.total.amount', 'nope.field', 'vars.x', 'trigger.order' ] as $path ) {
            $this->assertSame( self::expect( 'invalid_config', "{{ $path }} isn’t an available variable.", 'note' ), self::issue_of( 'note', [ 'note' => "{{ $path }}" ] ), $path );
        }
    }

    public function test_steps_read_outputs_of_the_steps_above(): void {
        $this->assertNull( self::issue_of( 'note', [ 'note' => 'Sent to {{ steps.email.to }}, {{ steps.c.result }}' ] ) );
        $this->assertNull( self::issue_of( 'meta', [ 'meta' => '{{ steps.c.result }}' ] ), 'both branches see the condition' );
        $this->assertNull( self::issue_of( 'action-mv0pf42q0l81', [ 'note' => '{{ steps.email.to }}' ], [], 'action-mv0pf42q0l81' ), 'any step id' );

        $this->assertSame(
            self::expect( 'missing_data', '{{ steps.email.to }} needs the Send internal email step above this one. Move it above, or remove the variable.', 'value' ),
            self::issue_of( 'meta', [ 'meta' => '{{ steps.email.to }}' ] ),
            'the other branch'
        );
        $this->assertSame(
            self::expect( 'missing_data', '{{ steps.note.type }} needs the Add order note step above this one. Move it above, or remove the variable.', 'subject' ),
            self::issue_of( 'email', [ 'email' => '{{ steps.note.type }}' ] ),
            'a step below'
        );
        $this->assertSame( self::expect( 'invalid_config', '{{ steps.email.subject }} isn’t an available variable.', 'note' ), self::issue_of( 'note', [ 'note' => '{{ steps.email.subject }}' ] ), 'not an output' );
        $this->assertSame( self::expect( 'invalid_config', '{{ steps.nope.to }} comes from a step that isn’t in this workflow anymore. Add that step above this one, or remove the variable.', 'note' ), self::issue_of( 'note', [ 'note' => '{{ steps.nope.to }}' ] ), 'a deleted step' );
        $this->assertSame( self::expect( 'invalid_config', '{{ steps.email.to.x }} isn’t an available variable.', 'note' ), self::issue_of( 'note', [ 'note' => '{{ steps.email.to.x }}' ] ) );
    }

    public function test_step_ids_with_digits_and_hyphens_in_paths(): void {
        $issue = self::issue_of( 'meta', [ 'meta' => '{{ steps.action-mv0pf42q0l81.type }}' ], [], 'action-mv0pf42q0l81' );
        $this->assertSame( 'missing_data', $issue['code'], 'the path parses: the note is on the other branch' );
    }

    public function test_filters_and_malformed_tags(): void {
        $this->assertNull( self::issue_of( 'note', [ 'note' => '{{ order.total | money }} {{ customer.first_name | default: "there" }}' ] ) );
        $this->assertSame( self::expect( 'invalid_config', 'shout isn’t a filter. Available filters: default, upper, lower, join, date, money.', 'note' ), self::issue_of( 'note', [ 'note' => '{{ order.number | shout }}' ] ) );
        $this->assertSame( self::expect( 'invalid_config', '{{ order. }} is written incorrectly. Use {{ variable }} or {{ variable | filter }}.', 'note' ), self::issue_of( 'note', [ 'note' => '{{ order. }}' ] ) );
        $this->assertSame( self::expect( 'invalid_config', '{{ order }} is written incorrectly. Use {{ variable }} or {{ variable | filter }}.', 'note' ), self::issue_of( 'note', [ 'note' => '{{ order }}' ] ) );
    }

    public function test_condition_rules_stay_on_data_fields(): void {
        $issue = self::issue_of(
            'c',
            [],
            [
                [
                    'field'    => 'steps.email.to',
                    'operator' => 'equal',
                    'value'    => 'x',
                ],
            ]
        );
        $this->assertSame( self::expect( 'invalid_config', 'Choose data for each rule.', 'rules' ) + [ 'rule' => [ 0 ] ], $issue );
    }

    public function test_issues_cover_live_and_draft(): void {
        $graph = GraphSchema::prepare(
            [
                'nodes' => [ self::trigger(), self::note( '' ) ],
                'edges' => [ self::edge( 't', 'note' ) ],
            ]
        );

        $none = Validator::issues(
            [
                'name'  => 'A',
                'graph' => $graph,
                'draft' => null,
            ]
        );
        $this->assertSame( [ 'invalid_config' ], array_column( $none['live'], 'code' ) );
        $this->assertNull( $none['draft'] );

        $both = Validator::issues(
            [
                'name'  => '',
                'graph' => $graph,
                'draft' => $graph,
            ]
        );
        $this->assertSame( [ 'name_required', 'invalid_config' ], array_column( $both['draft'], 'code' ) );
    }

    public function test_the_400_summary_names_the_action(): void {
        $one = [ [ 'code' => 'no_action' ] ];
        $two = [ [ 'code' => 'no_action' ], [ 'code' => 'no_trigger' ] ];

        $this->assertSame( 'Fix 1 issue before you activate this workflow.', Validator::issues_error( $one, 'activate' )->get_error_message() );
        $this->assertSame( 'Fix 2 issues before you activate this workflow.', Validator::issues_error( $two, 'activate' )->get_error_message() );
        $this->assertSame( 'Fix 2 issues before you update this workflow.', Validator::issues_error( $two, 'apply' )->get_error_message() );
        $this->assertSame( $two, Validator::issues_error( $two, 'apply' )->get_error_data()['errors'] );
    }

    public function test_the_filter_list_comes_from_the_registry(): void {
        $filters = new \YayMatik\Workflow\Core\Data\Filters();
        $filters->add( 'shout', 'strtoupper' );
        $errors = ( new \YayMatik\Workflow\Core\Data\Template( $filters ) )->errors( '{{ order.number | nope }}' );

        $this->assertSame( [ 'nope isn’t a filter. Available filters: default, upper, lower, join, date, money, shout.' ], $errors );
    }

    /** @return array[] Cases shared with the admin app's `availableVariables()` (tests/fixtures/variables.json). */
    public function variable_cases(): array {
        $cases = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/variables.json' ), true )['cases'];
        $sets  = [];
        foreach ( $cases as $case ) {
            if ( isset( $sets[ $case['name'] ] ) ) {
                throw new \LogicException( 'Two variable cases with the same name: ' . $case['name'] );
            }
            $sets[ $case['name'] ] = [ $case['graph'], $case['nodeId'], $case['keys'] ];
        }
        return $sets;
    }

    /**
     * @dataProvider variable_cases
     * @param array    $graph   Graph as stored.
     * @param string   $node_id The step.
     * @param string[] $keys    Expected keys, in order.
     */
    public function test_variables_a_step_can_use( array $graph, string $node_id, array $keys ): void {
        $this->assertSame( $keys, array_column( Validator::variables( GraphSchema::prepare( $graph ), $node_id ), 'key' ) );
    }

    public function test_variables_carry_label_and_type(): void {
        $graph  = GraphSchema::prepare(
            [
                'nodes' => [ self::trigger(), self::node( 'c', 'condition', 'condition' ), self::note( 'n' ) ],
                'edges' => [ self::edge( 't', 'c' ), self::edge( 'c', 'note', 'then' ) ],
            ]
        );
        $by_key = array_column( Validator::variables( $graph, 'note' ), null, 'key' );
        $this->assertSame(
            [
                'key'   => 'order.total',
                'label' => 'Order total',
                'type'  => 'money',
            ],
            $by_key['order.total']
        );
        $this->assertSame( 'Customer first name', $by_key['order.customer.first_name']['label'], 'a relation reads as the related field' );
        $this->assertArrayNotHasKey( 'order.customer', $by_key, 'the relation itself has no text' );
        $this->assertSame( 'enum', $by_key['steps.c.result']['type'] );
    }

    public function test_every_listed_variable_passes_the_tag_check(): void {
        $graph  = GraphSchema::prepare(
            [
                'nodes' => [ self::trigger(), self::node( 'c', 'condition', 'condition' ), self::note( 'n' ) ],
                'edges' => [ self::edge( 't', 'c' ), self::edge( 'c', 'note', 'then' ) ],
            ]
        );
        $tags   = implode( ' ', array_map( fn( array $v ) => '{{ ' . $v['key'] . ' }}', Validator::variables( $graph, 'note' ) ) );
        $graph  = GraphSchema::prepare(
            [
                'nodes' => [ self::trigger(), self::node( 'c', 'condition', 'condition' ), self::note( $tags ) ],
                'edges' => [ self::edge( 't', 'c' ), self::edge( 'c', 'note', 'then' ) ],
            ]
        );
        $issues = Validator::errors(
            [
                'name'  => 'Alert',
                'graph' => $graph,
            ]
        );
        $this->assertSame( [], array_values( array_filter( $issues, fn( array $issue ) => 'note' === ( $issue['nodeId'] ?? null ) ) ) );
    }

    public function test_list_issues_match_issues(): void {
        $workflow = [
            'id'    => '7',
            'name'  => 'Alert',
            'graph' => GraphSchema::blank(),
            'draft' => null,
        ];
        $this->assertSame( Validator::issues( $workflow ), Validator::list_issues( $workflow ) );
    }

    public function test_list_issues_survive_a_workflow_that_cant_be_checked(): void {
        $log      = tempnam( sys_get_temp_dir(), 'yay-log' );
        $previous = ini_set( 'error_log', $log );
        try {
            // No `nodes`: errors() throws on it.
            $issues = Validator::list_issues(
                [
                    'id'    => '42',
                    'name'  => 'Broken',
                    'graph' => [ 'edges' => [] ],
                    'draft' => null,
                ]
            );
        } finally {
            ini_set( 'error_log', (string) $previous );
        }
        $this->assertSame(
            [
                'live'  => [],
                'draft' => [],
            ],
            $issues
        );
        $this->assertStringContainsString( 'Could not check workflow 42', (string) file_get_contents( $log ) );
        unlink( $log );
    }

    public function test_warns_when_an_action_can_set_off_its_own_trigger(): void {
        $graph = GraphSchema::prepare(
            [
                'nodes' => [
                    self::node( 't', 'trigger', 'order_status_changed' ),
                    self::node(
                        'a',
                        'action',
                        'change_order_status',
                        [ 'status' => 'completed' ]
                    ),
                    self::node( 'n', 'action', 'add_order_note', [ 'note' => 'Hi' ] ),
                ],
                'edges' => [ self::edge( 't', 'a' ), self::edge( 'a', 'n' ) ],
            ]
        );

        $this->assertSame(
            [
                [
                    'code'    => 'may_refire',
                    'nodeId'  => 'a',
                    'message' => Messages::get( 'mayRefire' ),
                ],
            ],
            Validator::warnings( $graph )
        );
        $errors = Validator::errors(
            [
                'name'  => 'Alert',
                'graph' => $graph,
            ]
        );
        $this->assertSame( [], array_values( array_filter( $errors, fn( array $error ) => 'may_refire' === $error['code'] ) ) );
    }

    /**
     * Runs `$check` with module `acme`'s steps registered: trigger `acme_points` (customer) and
     * action `acme_act` with these args.
     *
     * @param array    $action
     * @param callable $check
     * @return mixed What `$check` returns.
     */
    private static function with_acme( array $action, callable $check ) {
        $module = Extensions::collect(
            [],
            function ( $registrar ) use ( $action ) {
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
                        'facts'       => [
                            'points' => [
                                'label' => 'Points earned',
                                'type'  => 'number',
                            ],
                        ],
                    ]
                );
                $module->action(
                    'acme_act',
                    $action + [
                        'label'       => 'Act',
                        'description' => 'Does it.',
                        'run'         => fn( array $config, $run ) => $run->done(),
                    ]
                );
            }
        )[0];
        $steps  = new \ReflectionProperty( StepRegistry::class, 'steps' );
        $steps->setAccessible( true );
        $before = StepRegistry::all();
        $steps->setValue(
            null,
            $before + [
                'acme_points' => $module->triggers()[0],
                'acme_act'    => $module->actions()[0],
            ]
        );
        try {
            return $check();
        } finally {
            $steps->setValue( null, $before );
        }
    }

    /**
     * Prepared "trigger → acme_act" graph.
     *
     * @param string $trigger
     */
    private static function acme_graph( string $trigger ): array {
        return GraphSchema::prepare(
            [
                'nodes' => [ self::node( 't', 'trigger', $trigger ), self::node( 'a', 'action', 'acme_act' ) ],
                'edges' => [ self::edge( 't', 'a' ) ],
            ]
        );
    }

    public function test_an_action_setup_issue_blocks_activating(): void {
        $issues = self::with_acme(
            [ 'setup_issue' => fn() => 'Create a design in Acme first.' ],
            fn() => [
                Validator::errors(
                    [
                        'name'   => 'Alert',
                        'status' => 'inactive',
                        'graph'  => self::acme_graph( 'acme_points' ),
                    ]
                ),
                Validator::errors(
                    [
                        'name'   => 'Alert',
                        'status' => 'active',
                        'graph'  => self::acme_graph( 'acme_points' ),
                    ]
                ),
            ]
        );

        $this->assertSame(
            [
                [
                    'code'    => 'setup_issue',
                    'message' => 'Create a design in Acme first.',
                    'nodeId'  => 'a',
                ],
            ],
            $issues[0]
        );
        $this->assertSame( [], $issues[1], 'an active workflow keeps running' );
    }

    public function test_validate_gets_the_data_items_the_trigger_gives(): void {
        $validate = fn( array $config, array $available ) => in_array( 'order', $available, true ) ? [] : [ '' => 'This design needs an order.' ];
        $codes    = self::with_acme(
            [ 'validate' => $validate ],
            fn() => [
                array_column( self::issues( self::acme_graph( 'acme_points' )['nodes'], self::acme_graph( 'acme_points' )['edges'] ), 'message', 'nodeId' ),
                array_column( self::issues( self::acme_graph( 'order_created' )['nodes'], self::acme_graph( 'order_created' )['edges'] ), 'message', 'nodeId' ),
            ]
        );

        $this->assertSame( [ 'a' => 'This design needs an order.' ], $codes[0] );
        $this->assertSame( [], $codes[1] );
    }

    public function test_a_fact_is_a_variable_of_its_trigger_only(): void {
        $note    = [
            'fields' => [
                'note' => [
                    'type'      => 'text',
                    'label'     => 'Note',
                    'variables' => true,
                ],
            ],
        ];
        $graph   = fn( string $trigger, string $text ) => GraphSchema::prepare(
            [
                'nodes' => [ self::node( 't', 'trigger', $trigger ), self::node( 'a', 'action', 'acme_act', [ 'note' => $text ] ) ],
                'edges' => [ self::edge( 't', 'a' ) ],
            ]
        );
        $results = self::with_acme(
            $note,
            fn() => [
                Validator::errors(
                    [
                        'name'  => 'A',
                        'graph' => $graph( 'acme_points', 'You got {{ trigger.points }} points' ),
                    ]
                ),
                array_column(
                    Validator::errors(
                        [
                            'name'  => 'A',
                            'graph' => $graph( 'acme_points', '{{ trigger.nope }}' ),
                        ]
                    ),
                    'message'
                ),
                array_column(
                    Validator::errors(
                        [
                            'name'  => 'A',
                            'graph' => $graph( 'order_created', '{{ trigger.points }}' ),
                        ]
                    ),
                    'message'
                ),
                array_column( Validator::variables( $graph( 'acme_points', '' ), 'a' ), 'key' )[0],
            ]
        );

        $this->assertSame( [], $results[0] );
        $this->assertSame( [ Messages::get( 'unknownVariable', '{{ trigger.nope }}' ) ], $results[1] );
        $this->assertSame( [ Messages::get( 'unknownVariable', '{{ trigger.points }}' ) ], $results[2] );
        $this->assertSame( 'trigger.points', $results[3], 'facts are listed first' );
    }

    public function test_a_condition_rule_on_a_fact(): void {
        $criteria = new \YayMatik\Workflow\Conditions\Criteria();
        $config   = $criteria->sanitize(
            [
                'match' => 'all',
                'rules' => [
                    [
                        'field'    => 'trigger.points',
                        'operator' => 'greater_than',
                        'value'    => '10',
                    ],
                ],
            ]
        );
        $scope    = function ( int $points ) {
            $scope = new \YayMatik\Workflow\Core\Data\Scope( [ 'trigger' => [ 'points' => $points ] ], null, [] );
            $scope->set_trigger( StepRegistry::get( 'acme_points' ) );
            return $scope;
        };
        $results  = self::with_acme(
            [],
            fn() => [
                $criteria->validate( $config ),
                $criteria->uses( $config ),
                $criteria->context_error( $config, [], StepRegistry::get( 'acme_points' ) ),
                $criteria->evaluate( $scope( 42 ), $config ),
                $criteria->evaluate( $scope( 5 ), $config ),
            ]
        );

        $this->assertSame( [ true, [], null, true, false ], $results );
        $graph  = fn( string $trigger ) => GraphSchema::prepare(
            [
                'nodes' => [ self::node( 't', 'trigger', $trigger ), self::node( 'c', 'condition', 'condition', $config ), self::node( 'a', 'action', 'acme_act' ) ],
                'edges' => [ self::edge( 't', 'c' ), self::edge( 'c', 'a', 'then' ) ],
            ]
        );
        $issues = self::with_acme(
            [],
            fn() => [
                array_column(
                    Validator::errors(
                        [
                            'name'  => 'A',
                            'graph' => $graph( 'acme_points' ),
                        ]
                    ),
                    'code',
                    'nodeId'
                ),
                array_column(
                    Validator::errors(
                        [
                            'name'  => 'A',
                            'graph' => $graph( 'order_created' ),
                        ]
                    ),
                    'message',
                    'nodeId'
                ),
            ]
        );
        $this->assertArrayNotHasKey( 'c', $issues[0] );
        $this->assertSame( Messages::get( 'ruleVariable' ), $issues[1]['c'], 'a fact of another trigger' );
    }

    public function test_emits_drives_the_may_refire_warning(): void {
        $warnings = self::with_acme(
            [ 'emits' => [ 'acme_points' ] ],
            fn() => [ Validator::warnings( self::acme_graph( 'acme_points' ) ), Validator::warnings( self::acme_graph( 'order_created' ) ) ]
        );

        $this->assertSame( [ 'may_refire' ], array_column( $warnings[0], 'code' ) );
        $this->assertSame( [], $warnings[1] );
    }

    public function test_note_trigger_warns_only_with_skip_off(): void {
        $graph = fn( bool $skip ) => GraphSchema::prepare(
            [
                'nodes' => [
                    self::node( 't', 'trigger', 'order_note_added', [ 'skip_yaymatik' => $skip ] ),
                    self::node( 'a', 'action', 'change_order_status', [ 'status' => 'completed' ] ),
                    self::node( 'n', 'action', 'add_order_note', [ 'note' => 'Hi' ] ),
                ],
                'edges' => [ self::edge( 't', 'a' ), self::edge( 'a', 'n' ) ],
            ]
        );

        $this->assertSame( [], Validator::warnings( $graph( true ) ) );
        $this->assertSame( [ 'a', 'n' ], array_column( Validator::warnings( $graph( false ) ), 'nodeId' ) );
    }

    public function test_change_order_status_can_set_off_order_refunded(): void {
        $graph = GraphSchema::prepare(
            [
                'nodes' => [
                    self::node( 't', 'trigger', 'order_refunded' ),
                    self::node( 'a', 'action', 'change_order_status', [ 'status' => 'refunded' ] ),
                ],
                'edges' => [ self::edge( 't', 'a' ) ],
            ]
        );

        $this->assertSame( [ 'a' ], array_column( Validator::warnings( $graph ), 'nodeId' ) );
    }

    public function test_no_warning_on_order_paid_whose_order_cant_start_it_again(): void {
        $graph = GraphSchema::prepare(
            [
                'nodes' => [
                    self::node( 't', 'trigger', 'order_paid' ),
                    self::node( 'a', 'action', 'change_order_status', [ 'status' => 'completed' ] ),
                ],
                'edges' => [ self::edge( 't', 'a' ) ],
            ]
        );

        $this->assertSame( [], Validator::warnings( $graph ) );
    }

    public function test_no_warning_on_another_trigger_or_a_graph_without_a_trigger(): void {
        $action = self::node( 'a', 'action', 'change_order_status', [ 'status' => 'completed' ] );
        $graph  = GraphSchema::prepare(
            [
                'nodes' => [ self::node( 't', 'trigger', 'order_created' ), $action ],
                'edges' => [ self::edge( 't', 'a' ) ],
            ]
        );
        $this->assertSame( [], Validator::warnings( $graph ) );

        $no_step = GraphSchema::prepare(
            [
                'nodes' => [ self::node( 't', 'trigger', null ), $action ],
                'edges' => [ self::edge( 't', 'a' ) ],
            ]
        );
        $this->assertSame( [], Validator::warnings( $no_step ) );
    }

    public function test_a_custom_field_is_not_a_known_merge_tag(): void {
        $errors = self::issues(
            [
                self::trigger(),
                self::node(
                    'note',
                    'action',
                    'add_order_note',
                    [
                        'note' => 'Hi {{ order.meta }}',
                        'type' => 'private',
                    ]
                ),
            ],
            [ self::edge( 't', 'note' ) ]
        );
        $this->assertSame( Messages::get( 'unknownVariable', '{{ order.meta }}' ), $errors[0]['message'] );
    }
}
