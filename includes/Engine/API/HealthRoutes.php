<?php
namespace YayMatik\Engine\API;

use YayMatik\Utils\SingletonTrait;
use YayMatik\Workflow\Core\Runtime\HealthCheck;

defined( 'ABSPATH' ) || exit;

/** GET /health/checks, POST /health/fix. */
class HealthRoutes {

    use SingletonTrait;

    protected function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route(
            RestAPI::REST_NAMESPACE,
            '/health/checks',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'checks' ],
                'permission_callback' => [ RestAPI::get_instance(), 'can_manage' ],
            ]
        );
        register_rest_route(
            RestAPI::REST_NAMESPACE,
            '/health/fix',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'fix' ],
                'permission_callback' => [ RestAPI::get_instance(), 'can_manage' ],
                'args'                => [
                    'check' => [
                        'required' => true,
                        'type'     => 'string',
                        // `jobs_late` has no fix: a 400 like an unknown check.
                        'enum'     => array_keys( HealthCheck::FIXES ),
                    ],
                ],
            ]
        );
    }

    public function checks() {
        return rest_ensure_response(
            [
                'checks'     => HealthCheck::run(),
                'extensions' => HealthCheck::extensions(),
                'checkedAt'  => gmdate( 'c' ),
            ]
        );
    }

    /**
     * Fixes what the check finds now; run ids never come from the client.
     *
     * @param \WP_REST_Request $request
     */
    public function fix( \WP_REST_Request $request ) {
        return rest_ensure_response(
            [
                'fixed'  => HealthCheck::fix( (string) $request['check'] ),
                'checks' => HealthCheck::run(),
            ]
        );
    }
}
