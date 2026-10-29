<?php
namespace YayMatik\Engine\API;

use YayMatik\Engine\FEPages\UnsubscribePage;
use YayMatik\Utils\SingletonTrait;
use YayMatik\Workflow\Core\Storage\Settings;

defined( 'ABSPATH' ) || exit;

/** GET / PUT /settings, keys in camelCase. */
class SettingsRoutes {

    use SingletonTrait;

    protected function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route(
            RestAPI::REST_NAMESPACE,
            '/settings',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_settings' ],
                    'permission_callback' => [ RestAPI::get_instance(), 'can_manage' ],
                ],
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'update_settings' ],
                    'permission_callback' => [ RestAPI::get_instance(), 'can_manage' ],
                ],
            ]
        );
    }

    public function get_settings() {
        return rest_ensure_response( self::present( Settings::all() ) );
    }

    /**
     * Partial body; keys left out keep their value.
     *
     * @param \WP_REST_Request $request
     */
    public function update_settings( \WP_REST_Request $request ) {
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            return new \WP_Error( 'yaymatik_invalid_body', __( 'Send the settings as a JSON object.', 'yaymatik' ), [ 'status' => 400 ] );
        }
        $changes = [];
        foreach ( array_keys( Settings::defaults() ) as $key ) {
            if ( array_key_exists( self::camel( $key ), $body ) ) {
                $changes[ $key ] = $body[ self::camel( $key ) ];
            }
        }

        $result = Settings::update( $changes );
        if ( is_wp_error( $result ) ) {
            $data           = $result->get_error_data();
            $data['errors'] = array_map(
                fn( $error ) => [ 'field' => self::camel( $error['field'] ) ] + $error,
                $data['errors']
            );
            return new \WP_Error( $result->get_error_code(), $result->get_error_message(), $data );
        }
        return rest_ensure_response( self::present( $result ) );
    }

    /**
     * @param array $settings
     */
    private static function present( array $settings ): array {
        $out = [];
        foreach ( $settings as $key => $value ) {
            $out[ self::camel( $key ) ] = $value;
        }
        $out['unsubscribeDefault'] = UnsubscribePage::default_done_body();
        return $out;
    }

    /**
     * @param string $key
     */
    private static function camel( string $key ): string {
        return lcfirst( str_replace( '_', '', ucwords( $key, '_' ) ) );
    }
}
