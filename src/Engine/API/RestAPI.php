<?php
namespace YayAutomation\Engine\API;

use YayAutomation\Utils\SingletonTrait;
use YayAutomation\Engine\BEPages\AdminPage;
use YayAutomation\Engine\Workflows\WorkflowRepository;
use YayAutomation\Engine\Workflows\WorkflowValidator;

defined( 'ABSPATH' ) || exit;

/** REST routes under yay-automation/v1 */
class RestAPI {

    use SingletonTrait;

    public const REST_NAMESPACE = 'yay-automation/v1';

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
    }

    public function can_manage() {
        return current_user_can( AdminPage::CAPABILITY );
    }

    public function get_health() {
        return rest_ensure_response(
            [
                'ok'      => true,
                'version' => YAY_AUTOMATION_VERSION,
            ]
        );
    }

    public function list_workflows() {
        return rest_ensure_response( WorkflowRepository::all() );
    }

    public function get_workflow( \WP_REST_Request $request ) {
        return WorkflowRepository::find( (int) $request['id'] ) ?? $this->not_found();
    }

    /**
     * Always inactive; issues are allowed.
     *
     * @param \WP_REST_Request $request Request.
     */
    public function create_workflow( \WP_REST_Request $request ) {
        $workflow = WorkflowValidator::prepare_new( $request->get_json_params() );
        if ( is_wp_error( $workflow ) ) {
            return $workflow;
        }
        $created = WorkflowRepository::create( $workflow['name'], $workflow['graph'] );
        if ( is_wp_error( $created ) ) {
            return $created;
        }
        return new \WP_REST_Response( $created, 201 );
    }

    /**
     * `{ name?, graph? }`, issues allowed. The name applies at once. The graph replaces the live one
     * when inactive; when active it goes into the draft (cleared again if it matches the live graph).
     * No-op changes aren't written, so they don't move "Last updated" or the draft time.
     *
     * @param \WP_REST_Request $request Request.
     */
    public function update_workflow( \WP_REST_Request $request ) {
        $changes = WorkflowValidator::prepare_changes( $request->get_json_params() );
        if ( is_wp_error( $changes ) ) {
            return $changes;
        }

        return $this->with_workflow(
            (int) $request['id'],
            function ( int $id, array $current ) use ( $changes ) {
                $name       = isset( $changes['name'] ) && $changes['name'] !== $current['name'] ? $changes['name'] : null;
                $live_graph = null;
                if ( isset( $changes['graph'] ) ) {
                    $same_as_live = WorkflowValidator::same_graph( $changes['graph'], $current['graph'] );
                    if ( 'active' === $current['status'] ) {
                        $draft = $same_as_live ? null : $changes['graph'];
                        if ( ! WorkflowValidator::same_graph( $draft, $current['draft'] ) ) {
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
    }

    /**
     * Turning on needs a workflow without issues. Already active → returned as is.
     *
     * @param \WP_REST_Request $request Request.
     */
    public function activate_workflow( \WP_REST_Request $request ) {
        return $this->with_workflow(
            (int) $request['id'],
            function ( int $id, array $current ) {
                if ( 'active' === $current['status'] ) {
                    return true;
                }
                $errors = WorkflowValidator::errors( $current );
                return $errors ? WorkflowValidator::issues_error( $errors ) : WorkflowRepository::activate( $id );
            }
        );
    }

    /**
     * Always allowed, even with issues. A draft becomes the workflow's content, so no edit is lost.
     * Already inactive → returned as is.
     *
     * @param \WP_REST_Request $request Request.
     */
    public function deactivate_workflow( \WP_REST_Request $request ) {
        return $this->with_workflow(
            (int) $request['id'],
            function ( int $id, array $current ) {
                if ( 'active' !== $current['status'] ) {
                    return true;
                }
                return WorkflowRepository::deactivate( $id, $current['draft'] );
            }
        );
    }

    /**
     * The draft replaces the live graph if it has no issues. No draft → 409.
     *
     * @param \WP_REST_Request $request Request.
     */
    public function apply_workflow( \WP_REST_Request $request ) {
        return $this->with_workflow(
            (int) $request['id'],
            function ( int $id, array $current ) {
                if ( null === $current['draft'] ) {
                    return new \WP_Error( 'yay_automation_no_draft', __( 'There are no changes to apply.', 'yay-automation' ), [ 'status' => 409 ] );
                }
                $errors = WorkflowValidator::errors(
                    [
                        'name'  => $current['name'],
                        'graph' => $current['draft'],
                    ]
                );
                return $errors ? WorkflowValidator::issues_error( $errors ) : WorkflowRepository::apply_draft( $id, $current['draft'] );
            }
        );
    }

    /**
     * Drops unapplied changes; the live graph is untouched.
     *
     * @param \WP_REST_Request $request Request.
     */
    public function discard_draft( \WP_REST_Request $request ) {
        return $this->with_workflow(
            (int) $request['id'],
            function ( int $id ) {
                WorkflowRepository::save_draft( $id, null );
                return true;
            }
        );
    }

    /**
     * Runs `$change( $id, $current )` while holding a per-workflow lock, then returns the workflow
     * as stored. Draft saves, apply and deactivate read then write the draft; without the lock an
     * autosave landing mid-apply could be wiped, or leave a draft on an inactive workflow.
     *
     * @param int      $id     Workflow id.
     * @param callable $change Returns true on success, a WP_Error, or a WP_REST_Response to send as is
     *                         (e.g. after a delete, when there's no workflow left to return).
     * @return array|\WP_Error|\WP_REST_Response
     */
    private function with_workflow( int $id, callable $change ) {
        global $wpdb;
        $lock = 'yay_automation_workflow_' . $id;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 10 )', $lock ) ) ) {
            return new \WP_Error( 'yay_automation_busy', __( 'This workflow is being saved. Try again.', 'yay-automation' ), [ 'status' => 409 ] );
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
     * Permanent delete. An active workflow must be turned off first (409), so a running automation
     * can't disappear by accident.
     *
     * @param \WP_REST_Request $request Request.
     */
    public function delete_workflow( \WP_REST_Request $request ) {
        return $this->with_workflow(
            (int) $request['id'],
            function ( int $id, array $current ) {
                if ( 'active' === $current['status'] ) {
                    return new \WP_Error(
                        'yay_automation_delete_active',
                        __( 'Because this workflow is still turned on, it couldn’t be deleted. Turn it off and try again.', 'yay-automation' ),
                        [ 'status' => 409 ]
                    );
                }
                if ( ! WorkflowRepository::delete( $id ) ) {
                    return $this->not_found();
                }
                return rest_ensure_response( [ 'deleted' => true ] );
            }
        );
    }

    private function not_found(): \WP_Error {
        return new \WP_Error( 'yay_automation_not_found', __( 'Workflow not found.', 'yay-automation' ), [ 'status' => 404 ] );
    }
}
