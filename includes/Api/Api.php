<?php
namespace YayMatik\Api;

use YayMatik\Workflow\Core\Storage\VersionStore;

defined( 'ABSPATH' ) || exit;

/** The Extension API version, independent of the plugin's (docs/extension-api.md). */
final class Api {

    public const VERSION = '1.1.0';

    private function __construct() {
    }

    /**
     * Values of one config field still used by a step of this id in any version or draft (docs/extension-api.md §5.6).
     *
     * @param string $step_id
     * @param string $field
     * @return array
     */
    public static function references( string $step_id, string $field ): array {
        $values = [];
        foreach ( VersionStore::graphs_with_step( $step_id ) as $graph ) {
            foreach ( is_array( $graph['nodes'] ?? null ) ? $graph['nodes'] : [] as $node ) {
                $value = $node['data']['config'][ $field ] ?? null;
                if ( ( $node['data']['block'] ?? null ) === $step_id && is_scalar( $value ) && '' !== $value ) {
                    $values[] = $value;
                }
            }
        }
        return array_values( array_unique( $values, SORT_REGULAR ) );
    }
}
