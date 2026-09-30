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
     * No-op changes aren't written, so they don't move "Last updated".
     *
     * @param \WP_REST_Request $request Request.
     */
    public function update_workflow( \WP_REST_Request $request ) {
        $id      = (int) $request['id'];
        $current = WorkflowRepository::find( $id );
        if ( ! $current ) {
            return $this->not_found();
        }
        $changes = WorkflowValidator::prepare_changes( $request->get_json_params() );
        if ( is_wp_error( $changes ) ) {
            return $changes;
        }

        if ( isset( $changes['name'] ) && $changes['name'] !== $current['name'] ) {
            $result = WorkflowRepository::rename( $id, $changes['name'] );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }
        if ( isset( $changes['graph'] ) ) {
            // Loose ==: same keys and values, key order doesn't matter.
            $same_as_live = $changes['graph'] == $current['graph']; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
            if ( 'active' === $current['status'] ) {
                WorkflowRepository::save_draft( $id, $same_as_live ? null : $changes['graph'] );
            } elseif ( ! $same_as_live ) {
                $result = WorkflowRepository::save_graph( $id, $changes['graph'] );
                if ( is_wp_error( $result ) ) {
                    return $result;
                }
            }
        }
        return WorkflowRepository::find( $id );
    }

    /**
     * Turning on needs a workflow without issues. Already active → returned as is.
     *
     * @param \WP_REST_Request $request Request.
     */
    public function activate_workflow( \WP_REST_Request $request ) {
        $id      = (int) $request['id'];
        $current = WorkflowRepository::find( $id );
        if ( ! $current ) {
            return $this->not_found();
        }
        if ( 'active' !== $current['status'] ) {
            $errors = WorkflowValidator::errors( $current );
            if ( $errors ) {
                return WorkflowValidator::issues_error( $errors );
            }
            $result = WorkflowRepository::set_status( $id, 'active' );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }
        return WorkflowRepository::find( $id );
    }

    /**
     * Always allowed, even with issues. A draft becomes the workflow's content, so no edit is lost.
     *
     * @param \WP_REST_Request $request Request.
     */
    public function deactivate_workflow( \WP_REST_Request $request ) {
        $id      = (int) $request['id'];
        $current = WorkflowRepository::find( $id );
        if ( ! $current ) {
            return $this->not_found();
        }
        $result = WorkflowRepository::set_status( $id, 'inactive', $current['draft'] );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        WorkflowRepository::save_draft( $id, null );
        return WorkflowRepository::find( $id );
    }

    /**
     * The draft replaces the live graph if it has no issues. No draft → 409.
     *
     * @param \WP_REST_Request $request Request.
     */
    public function apply_workflow( \WP_REST_Request $request ) {
        $id      = (int) $request['id'];
        $current = WorkflowRepository::find( $id );
        if ( ! $current ) {
            return $this->not_found();
        }
        if ( null === $current['draft'] ) {
            return new \WP_Error( 'yay_automation_no_draft', __( 'There are no changes to apply.', 'yay-automation' ), [ 'status' => 409 ] );
        }
        $errors = WorkflowValidator::errors(
            [
                'name'  => $current['name'],
                'graph' => $current['draft'],
            ]
        );
        if ( $errors ) {
            return WorkflowValidator::issues_error( $errors );
        }
        $result = WorkflowRepository::save_graph( $id, $current['draft'] );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        WorkflowRepository::save_draft( $id, null );
        return WorkflowRepository::find( $id );
    }

    /**
     * Drops unapplied changes; the live graph is untouched.
     *
     * @param \WP_REST_Request $request Request.
     */
    public function discard_draft( \WP_REST_Request $request ) {
        $id = (int) $request['id'];
        if ( ! WorkflowRepository::find( $id ) ) {
            return $this->not_found();
        }
        WorkflowRepository::save_draft( $id, null );
        return WorkflowRepository::find( $id );
    }

    public function delete_workflow( \WP_REST_Request $request ) {
        if ( ! WorkflowRepository::delete( (int) $request['id'] ) ) {
            return $this->not_found();
        }
        return rest_ensure_response( [ 'deleted' => true ] );
    }

    private function not_found(): \WP_Error {
        return new \WP_Error( 'yay_automation_not_found', __( 'Workflow not found.', 'yay-automation' ), [ 'status' => 404 ] );
    }
}
