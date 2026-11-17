<?php
namespace YayMatik\Pro\Engine\API;

use YayMatik\Engine\API\RestAPI;
use YayMatik\Pro\Workflow\Actions\UpdateCustomerMeta;
use YayMatik\Workflow\Conditions\Criteria;
use YayMatik\Workflow\Core\Data\MetaKeys;
use YayMatik\Workflow\Core\Storage\WorkflowStore;

defined( 'ABSPATH' ) || exit;

/** GET /search/customer-meta-keys: the suggestions of a customer custom field name. */
final class CustomerMetaKeysRoute {

    public static function register(): void {
        add_action( 'rest_api_init', [ self::class, 'register_route' ] );
    }

    public static function register_route(): void {
        register_rest_route(
            RestAPI::REST_NAMESPACE,
            '/search/customer-meta-keys',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ self::class, 'keys' ],
                'permission_callback' => [ RestAPI::get_instance(), 'can_manage' ],
            ]
        );
    }

    /** Custom field names other workflows read in rules or write, live and draft: `{ keys: string[] }`. */
    public static function keys() {
        $keys = [];
        foreach ( WorkflowStore::all() as $workflow ) {
            foreach ( [ $workflow['graph'], $workflow['draft'] ?? [] ] as $graph ) {
                foreach ( $graph['nodes'] ?? [] as $node ) {
                    $block = $node['data']['block'] ?? '';
                    if ( Criteria::ID === $block ) {
                        $keys = array_merge( $keys, Criteria::meta_keys( $node['data']['config'], 'customer.meta' ) );
                    } elseif ( UpdateCustomerMeta::ID === $block && is_string( $node['data']['config']['key'] ?? null ) && '' !== $node['data']['config']['key'] ) {
                        $keys[] = $node['data']['config']['key'];
                    }
                }
            }
        }
        // A draft may hold a protected key: saves allow issues.
        $keys = array_values( array_unique( array_filter( $keys, fn( string $key ) => ! MetaKeys::user_blocked( $key ) ) ) );
        sort( $keys );
        return rest_ensure_response( [ 'keys' => $keys ] );
    }
}
