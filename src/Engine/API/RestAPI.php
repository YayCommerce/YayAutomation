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

    public function create_workflow( \WP_REST_Request $request ) {
        $workflow = WorkflowValidator::prepare( $request->get_json_params() );
        if ( is_wp_error( $workflow ) ) {
            return $workflow;
        }
        $created = WorkflowRepository::create( $workflow );
        if ( is_wp_error( $created ) ) {
            return $created;
        }
        return new \WP_REST_Response( $created, 201 );
    }

    public function update_workflow( \WP_REST_Request $request ) {
        $workflow = WorkflowValidator::prepare( $request->get_json_params() );
        if ( is_wp_error( $workflow ) ) {
            return $workflow;
        }
        return WorkflowRepository::update( (int) $request['id'], $workflow ) ?? $this->not_found();
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
