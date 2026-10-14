<?php
namespace YayAutomation\Tests\Unit\Definition;

use PHPUnit\Framework\TestCase;
use YayAutomation\Workflow\Definition\GraphSchema;
use YayAutomation\Workflow\Definition\Validator;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/**
 * Workflow issues (`errors()`): codes, messages and the step they point at. The first group pins
 * v0.1 output exactly, so the move to the Validator keeps it.
 */
final class ValidatorTest extends TestCase {

    /**
     * @param string      $id     Node id.
     * @param string      $kind   Kind.
     * @param string|null $block  Step id.
     * @param array       $config Config.
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
     * @param string      $source Source node.
     * @param string      $target Target node.
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
     * @param array  $nodes Nodes.
     * @param array  $edges Edges.
     * @param string $name  Name.
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
                [ 'invalid_config', 'Choose statuses from the list.', 't' ],
                [ 'invalid_config', 'Add a rule, or remove this condition.', 'c' ],
                [ 'invalid_config', '{{ order.foo }} isn’t an available variable.', 'note' ],
                [ 'invalid_config', 'Update order custom field needs a field name.', 'meta' ],
            ],
            array_map( fn( array $issue ) => [ $issue['code'], $issue['message'], $issue['nodeId'] ], $issues )
        );
        $this->assertSame( [ 'to', 'rules', 'note', 'key' ], array_column( $issues, 'field' ), 'each step issue names its field' );
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

    /**
     * @param string $code    Code.
     * @param string $message Message.
     * @param string $field   Field.
     */
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
            self::expect( 'missing_data', 'Send internal email data isn’t available at this step.', 'value' ),
            self::issue_of( 'meta', [ 'meta' => '{{ steps.email.to }}' ] ),
            'the other branch'
        );
        $this->assertSame(
            self::expect( 'missing_data', 'Add order note data isn’t available at this step.', 'subject' ),
            self::issue_of( 'email', [ 'email' => '{{ steps.note.type }}' ] ),
            'a step below'
        );
        $this->assertSame( self::expect( 'invalid_config', '{{ steps.email.subject }} isn’t an available variable.', 'note' ), self::issue_of( 'note', [ 'note' => '{{ steps.email.subject }}' ] ), 'not an output' );
        $this->assertSame( self::expect( 'invalid_config', '{{ steps.nope.to }} isn’t an available variable.', 'note' ), self::issue_of( 'note', [ 'note' => '{{ steps.nope.to }}' ] ) );
    }

    public function test_step_ids_with_digits_and_hyphens_in_paths(): void {
        $issue = self::issue_of( 'meta', [ 'meta' => '{{ steps.action-mv0pf42q0l81.type }}' ], [], 'action-mv0pf42q0l81' );
        $this->assertSame( 'missing_data', $issue['code'], 'the path parses: the note is on the other branch' );
    }

    public function test_filters_and_malformed_tags(): void {
        $this->assertNull( self::issue_of( 'note', [ 'note' => '{{ order.total | money }} {{ customer.first_name | default: "there" }}' ] ) );
        $this->assertSame( self::expect( 'invalid_config', 'shout isn’t a filter. Available filters: default, upper, lower, join, date, money.', 'note' ), self::issue_of( 'note', [ 'note' => '{{ order.number | shout }}' ] ) );
        $this->assertSame( self::expect( 'invalid_config', '{{ order. }} is written incorrectly. Use {{ variable }} or {{ variable | filter }}.', 'note' ), self::issue_of( 'note', [ 'note' => '{{ order. }}' ] ) );
        $this->assertSame( self::expect( 'invalid_config', '{{ order }} is written incorrectly. Use {{ variable }} or {{ variable | filter }}.', 'note' ), self::issue_of( 'note', [ 'note' => '{{ order }}' ] ), 'v0.1 printed it as written' );
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
        $this->assertSame( self::expect( 'invalid_config', 'Choose a variable for each rule.', 'rules' ), $issue );
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
        $filters = new \YayAutomation\Workflow\Data\Filters();
        $filters->add( 'shout', [ 'string' ], 'strtoupper' );
        $errors = ( new \YayAutomation\Workflow\Data\Template( $filters ) )->errors( '{{ order.number | nope }}' );

        $this->assertSame( [ 'nope isn’t a filter. Available filters: default, upper, lower, join, date, money, shout.' ], $errors );
    }

    /** @return array[] Cases shared with the admin app's `availableVariables()` (tests/fixtures/variables.json). */
    public function variable_cases(): array {
        $cases = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/variables.json' ), true )['cases'];
        $sets = [];
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
        $graph = GraphSchema::prepare(
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
        $graph = GraphSchema::prepare(
            [
                'nodes' => [ self::trigger(), self::node( 'c', 'condition', 'condition' ), self::note( 'n' ) ],
                'edges' => [ self::edge( 't', 'c' ), self::edge( 'c', 'note', 'then' ) ],
            ]
        );
        $tags  = implode( ' ', array_map( fn( array $v ) => '{{ ' . $v['key'] . ' }}', Validator::variables( $graph, 'note' ) ) );
        $graph = GraphSchema::prepare(
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
}
