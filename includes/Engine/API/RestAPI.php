<?php
namespace YayMatik\Engine\API;

use YayMatik\Engine\BEPages\AdminPage;
use YayMatik\Utils\SingletonTrait;
use YayMatik\Workflow\Core\Definition\GraphSchema;
use YayMatik\Workflow\Core\Email\EmailPreview;
use YayMatik\Workflow\Core\Definition\Messages;
use YayMatik\Workflow\Core\Definition\Validator;
use YayMatik\Workflow\Core\Runtime\RunPresenter;
use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\WorkflowRepository;

defined( 'ABSPATH' ) || exit;

/** REST routes under yaymatik/v1 */
class RestAPI {

    use SingletonTrait;

    public const REST_NAMESPACE = 'yaymatik/v1';

    protected function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            '/health',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_health' ],
                'permission_callback' => [ $this, 'can_manage' ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/workflows',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'list_workflows' ],
                    'permission_callback' => [ $this, 'can_manage' ],
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'create_workflow' ],
                    'permission_callback' => [ $this, 'can_manage' ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/workflows/(?P<id>\d+)',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_workflow' ],
                    'permission_callback' => [ $this, 'can_manage' ],
                ],
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'update_workflow' ],
                    'permission_callback' => [ $this, 'can_manage' ],
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'delete_workflow' ],
                    'permission_callback' => [ $this, 'can_manage' ],
                ],
            ]
        );

        foreach ( [ 'activate', 'deactivate', 'apply' ] as $action ) {
            register_rest_route(
                self::REST_NAMESPACE,
                '/workflows/(?P<id>\d+)/' . $action,
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, $action . '_workflow' ],
                    'permission_callback' => [ $this, 'can_manage' ],
                ]
            );
        }

        register_rest_route(
            self::REST_NAMESPACE,
            '/workflows/(?P<id>\d+)/draft',
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'discard_draft' ],
                'permission_callback' => [ $this, 'can_manage' ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/workflows/(?P<id>\d+)/versions',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_versions' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [
                    'page' => [
                        'type'    => 'integer',
                        'minimum' => 1,
                        'default' => 1,
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/workflows/(?P<id>\d+)/runs',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_runs' ],
                'permission_callback' => [ $this, 'can_manage' ],
            ]
        );

        // Body: { nodeId, config, graph? } — the editor's unsaved step and graph; graph defaults to the
        // workflow's draft, else its live graph.
        foreach ( [
            'preview'   => 'preview_email',
            'send-test' => 'send_test_email',
        ] as $route => $callback ) {
            register_rest_route(
                self::REST_NAMESPACE,
                '/workflows/(?P<id>\d+)/' . $route,
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, $callback ],
                    'permission_callback' => [ $this, 'can_email' ],
                ]
            );
        }

        register_rest_route(
            self::REST_NAMESPACE,
            '/runs/(?P<id>\d+)',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_run' ],
                'permission_callback' => [ $this, 'can_manage' ],
            ]
        );
    }

    public function can_manage() {
        return current_user_can( AdminPage::CAPABILITY );
    }

    /** Previews read store orders; a test email goes out from the store. */
    public function can_email() {
        return current_user_can( 'manage_woocommerce' );
    }

    /**
     * The email a step would send, rendered with a recent order or sample data.
     *
     * @param \WP_REST_Request $request
     */
    public function preview_email( \WP_REST_Request $request ) {
        $input = $this->email_input( $request );
        return is_wp_error( $input ) ? $input : rest_ensure_response( EmailPreview::render( ...$input ) );
    }

    /**
     * Sends that email to the logged-in user, subject prefixed "[Test]".
     *
     * @param \WP_REST_Request $request
     */
    public function send_test_email( \WP_REST_Request $request ) {
        $input = $this->email_input( $request );
        if ( is_wp_error( $input ) ) {
            return $input;
        }
        list( $graph, $node_id, $config ) = $input;
        return rest_ensure_response( EmailPreview::send_test( $graph, $node_id, $config, (string) wp_get_current_user()->user_email ) );
    }

    /**
     * [ graph, nodeId, config ] from a preview / send-test request.
     *
     * @param \WP_REST_Request $request
     * @return array|\WP_Error
     */
    private function email_input( \WP_REST_Request $request ) {
        $workflow = WorkflowRepository::find( (int) $request['id'] );
        if ( ! $workflow ) {
            return $this->not_found();
        }
        $body    = (array) $request->get_json_params();
        $node_id = is_string( $body['nodeId'] ?? null ) ? $body['nodeId'] : '';
        if ( '' === $node_id ) {
            return new \WP_Error( 'yaymatik_no_node', __( 'Choose an email step to preview.', 'yaymatik' ), [ 'status' => 400 ] );
        }
        $graph = $body['graph'] ?? ( $workflow['draft'] ?? $workflow['graph'] );
        return [ $graph, $node_id, $body['config'] ?? [] ];
    }

    public function get_health() {
        return rest_ensure_response(
            [
                'ok'      => true,
                'version' => YAYMATIK_VERSION,
            ]
        );
    }

    /** Every workflow with its issues. */
    public function list_workflows() {
        return rest_ensure_response(
            array_map(
                fn( array $workflow ) => $workflow + [ 'issues' => Validator::list_issues( $workflow ) ],
                WorkflowRepository::all()
            )
        );
    }

    /**
     * One workflow with the issues of its live graph and draft.
     *
     * @param \WP_REST_Request $request
     */
    public function get_workflow( \WP_REST_Request $request ) {
        $workflow = WorkflowRepository::find( (int) $request['id'] );
        return $workflow ? $workflow + [ 'issues' => Validator::list_issues( $workflow ) ] : $this->not_found();
    }

    /**
     * Always inactive; issues are allowed.
     *
     * @param \WP_REST_Request $request
     */
    public function create_workflow( \WP_REST_Request $request ) {
        $workflow = Validator::prepare_new( $request->get_json_params() );
        if ( is_wp_error( $workflow ) ) {
            return $workflow;
        }
        $created = WorkflowRepository::create( $workflow['name'], $workflow['graph'] );
        if ( is_wp_error( $created ) ) {
            return $created;
        }
        return new \WP_REST_Response( self::with_issues( $created ), 201 );
    }

    /**
     * `{ name?, graph? }`, issues allowed.
     *
     * @param \WP_REST_Request $request
     */
    public function update_workflow( \WP_REST_Request $request ) {
        $changes = Validator::prepare_changes( $request->get_json_params() );
        if ( is_wp_error( $changes ) ) {
            return $changes;
        }

        $updated = $this->with_workflow(
            (int) $request['id'],
            function ( int $id, array $current ) use ( $changes ) {
                // No-op changes aren't written, so they don't move "Last updated" or the draft time.
                $name       = isset( $changes['name'] ) && $changes['name'] !== $current['name'] ? $changes['name'] : null;
                $live_graph = null;
                if ( isset( $changes['graph'] ) ) {
                    $same_as_live = GraphSchema::same( $changes['graph'], $current['graph'] );
                    // Once turned on, the saved content only changes through Apply: edits go to the draft.
                    if ( $current['everActivated'] ) {
                        $draft = $same_as_live ? null : $changes['graph'];
                        if ( ! GraphSchema::same( $draft, $current['draft'] ) ) {
                            WorkflowRepository::save_draft( $id, $draft );
                        }
                    } elseif ( ! $same_as_live ) {
                        $live_graph = $changes['graph'];
                    }
                }
                if ( null !== $name || null !== $live_graph ) {
                    return WorkflowRepository::update_live( $id, $name, $live_graph );
                }
                return true;
            }
        );
        return self::with_issues( $updated );
    }

    /**
     * Turns the saved graph on when it and the draft have no issues; the draft is kept.
     *
     * @param \WP_REST_Request $request
     */
    public function activate_workflow( \WP_REST_Request $request ) {
        return self::with_issues(
            $this->with_workflow(
                (int) $request['id'],
                function ( int $id, array $current ) {
                    if ( 'active' === $current['status'] ) {
                        return true;
                    }
                    $errors = Validator::errors( $current );
                    // A broken draft shouldn't sit behind a running workflow.
                    if ( ! $errors && null !== $current['draft'] ) {
                        $errors = Validator::errors(
                            [
                                'name'  => $current['name'],
                                'graph' => $current['draft'],
                            ]
                        );
                    }
                    return $errors ? Validator::issues_error( $errors, 'activate' ) : WorkflowRepository::activate( $id );
                }
            )
        );
    }

    /**
     * Always allowed, even with issues; the draft stays.
     *
     * @param \WP_REST_Request $request
     */
    public function deactivate_workflow( \WP_REST_Request $request ) {
        return self::with_issues(
            $this->with_workflow(
                (int) $request['id'],
                function ( int $id, array $current ) {
                    if ( 'active' !== $current['status'] ) {
                        return true;
                    }
                    return WorkflowRepository::deactivate( $id );
                }
            )
        );
    }

    /**
     * The draft replaces the saved graph when it has no issues; the status doesn't change.
     *
     * @param \WP_REST_Request $request
     */
    public function apply_workflow( \WP_REST_Request $request ) {
        return self::with_issues(
            $this->with_workflow(
                (int) $request['id'],
                function ( int $id, array $current ) {
                    if ( null === $current['draft'] ) {
                        return new \WP_Error( 'yaymatik_no_draft', __( 'There are no draft changes to update.', 'yaymatik' ), [ 'status' => 409 ] );
                    }
                    $errors = Validator::errors(
                        [
                            'name'  => $current['name'],
                            'graph' => $current['draft'],
                        ]
                    );
                    return $errors ? Validator::issues_error( $errors, 'apply' ) : WorkflowRepository::apply_draft( $id, $current['draft'] );
                }
            )
        );
    }

    /**
     * Drops unapplied changes; the live graph is untouched.
     *
     * @param \WP_REST_Request $request
     */
    public function discard_draft( \WP_REST_Request $request ) {
        return self::with_issues(
            $this->with_workflow(
                (int) $request['id'],
                function ( int $id ) {
                    WorkflowRepository::save_draft( $id, null );
                    return true;
                }
            )
        );
    }

    /**
     * One page of versions, newest first: `{ items, hasMore }`.
     *
     * @param \WP_REST_Request $request
     */
    public function list_versions( \WP_REST_Request $request ) {
        $id = (int) $request['id'];
        if ( ! WorkflowRepository::find( $id ) ) {
            return $this->not_found();
        }
        return rest_ensure_response( WorkflowRepository::versions( $id, (int) $request['page'] ) );
    }

    /**
     * A workflow's newest runs (`RunPresenter::run()`).
     *
     * @param \WP_REST_Request $request
     */
    public function list_runs( \WP_REST_Request $request ) {
        $id = (int) $request['id'];
        if ( ! WorkflowRepository::find( $id ) ) {
            return $this->not_found();
        }
        $rows = RunStore::list( [ 'workflow_id' => $id ] );
        return rest_ensure_response(
            [
                'items'   => array_map( [ RunPresenter::class, 'run' ], array_slice( $rows, 0, RunStore::PER_PAGE ) ),
                'hasMore' => count( $rows ) > RunStore::PER_PAGE,
            ]
        );
    }

    /**
     * One run with its step rows (`RunPresenter::detail()`).
     *
     * @param \WP_REST_Request $request
     */
    public function get_run( \WP_REST_Request $request ) {
        $run = RunStore::find( (int) $request['id'] );
        if ( ! $run ) {
            return new \WP_Error( 'yaymatik_not_found', __( 'Run not found.', 'yaymatik' ), [ 'status' => 404 ] );
        }
        return rest_ensure_response( RunPresenter::detail( $run, RunStepStore::for_run( (int) $run['id'] ) ) );
    }

    /**
     * Runs `$change( $id, $current )` under a per-workflow lock, then returns the workflow as stored.
     *
     * @param int      $id
     * @param callable $change Returns true, a WP_Error, or a WP_REST_Response to send as is.
     * @return array|\WP_Error|\WP_REST_Response
     */
    private function with_workflow( int $id, callable $change ) {
        global $wpdb;
        // Draft saves, apply and deactivate read then write the draft: without the lock an autosave
        // landing mid-apply could be wiped, or a draft cleared by apply could reappear.
        $lock = 'yaymatik_workflow_' . $id;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 10 )', $lock ) ) ) {
            return new \WP_Error( 'yaymatik_busy', __( 'This workflow is being saved. Try again.', 'yaymatik' ), [ 'status' => 409 ] );
        }
        try {
            // Read inside the lock, so the change sees what the previous request wrote.
            $current = WorkflowRepository::find( $id );
            if ( ! $current ) {
                return $this->not_found();
            }
            $result = $change( $id, $current );
            if ( is_wp_error( $result ) || $result instanceof \WP_REST_Response ) {
                return $result;
            }
            return WorkflowRepository::find( $id ) ?? $this->not_found();
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
        }
    }

    /**
     * Permanent delete of an inactive workflow.
     *
     * @param \WP_REST_Request $request
     */
    public function delete_workflow( \WP_REST_Request $request ) {
        return $this->with_workflow(
            (int) $request['id'],
            function ( int $id, array $current ) {
                // A running automation can't disappear by accident.
                if ( 'active' === $current['status'] ) {
                    return new \WP_Error(
                        'yaymatik_delete_active',
                        Messages::get( 'deleteActive' ),
                        [ 'status' => 409 ]
                    );
                }
                // It exists (checked under the lock), so a false here is a failed delete, not a 404.
                if ( ! WorkflowRepository::delete( $id ) ) {
                    return new \WP_Error( 'yaymatik_delete_failed', __( 'Couldn’t delete the workflow. Try again.', 'yaymatik' ), [ 'status' => 500 ] );
                }
                return rest_ensure_response( [ 'deleted' => true ] );
            }
        );
    }

    /**
     * Adds `issues: { live, draft }` to a workflow; anything else passes through.
     *
     * @param array|\WP_Error|\WP_REST_Response $workflow
     * @return array|\WP_Error|\WP_REST_Response
     */
    private static function with_issues( $workflow ) {
        return is_array( $workflow ) ? $workflow + [ 'issues' => Validator::issues( $workflow ) ] : $workflow;
    }

    private function not_found(): \WP_Error {
        return new \WP_Error( 'yaymatik_not_found', __( 'Workflow not found.', 'yaymatik' ), [ 'status' => 404 ] );
    }
}
