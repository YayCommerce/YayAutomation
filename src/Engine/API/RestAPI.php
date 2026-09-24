<?php
namespace YayAutomation\Engine\API;

use YayAutomation\Utils\SingletonTrait;
use YayAutomation\Engine\BEPages\AdminPage;

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
}
