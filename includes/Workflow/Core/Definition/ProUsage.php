<?php
namespace YayMatik\Workflow\Core\Definition;

use YayMatik\Workflow\Conditions\Criteria;
use YayMatik\Workflow\Core\Data\Template;

defined( 'ABSPATH' ) || exit;

// Needs Pro: a Pro step, a rule on a Pro field, a group, a comparison with another field, or a Pro merge tag, while
// `ProCatalog::missing()` names them. While YayMatik Pro is active nothing is missing, so nothing needs it.

/** The steps of a workflow that need YayMatik Pro while it isn't here. */
final class ProUsage {

    /**
     * @param array $graph
     * @return string[] Ids of the steps that need it; empty with YayMatik Pro.
     */
    public static function of( array $graph ): array {
        $ids = [];
        foreach ( is_array( $graph['nodes'] ?? null ) ? $graph['nodes'] : [] as $node ) {
            if ( is_array( $node ) && self::node( $node ) ) {
                $ids[] = (string) ( $node['id'] ?? '' );
            }
        }
        return $ids;
    }

    /**
     * @param array $node
     */
    public static function node( array $node ): bool {
        $missing = ProCatalog::missing();
        if ( ! array_filter( $missing ) ) {
            return false;
        }
        $block = (string) ( $node['data']['block'] ?? '' );
        if ( in_array( $block, $missing['steps'], true ) ) {
            return true;
        }
        $config = is_array( $node['data']['config'] ?? null ) ? $node['data']['config'] : [];
        if ( Criteria::ID === $block && self::rules_need( is_array( $config['rules'] ?? null ) ? $config['rules'] : [], $missing ) ) {
            return true;
        }
        $tags = new Template();
        foreach ( self::texts( $config ) as $text ) {
            foreach ( $tags->paths( $text ) as $path ) {
                if ( self::missing_field( $path, $missing['fields'] ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array $items `rules` of a condition.
     * @param array $missing
     */
    private static function rules_need( array $items, array $missing ): bool {
        foreach ( $items as $item ) {
            $item  = is_array( $item ) ? $item : [];
            $group = is_array( $item['rules'] ?? null );
            if ( $group && in_array( 'groups', $missing['features'], true ) ) {
                return true;
            }
            foreach ( $group ? $item['rules'] : [ $item ] as $rule ) {
                $variable = is_array( $rule ) && 'variable' === ( $rule['valueType'] ?? '' );
                if ( $variable && in_array( 'variables', $missing['features'], true ) ) {
                    return true;
                }
                $keys = is_array( $rule ) ? [ (string) ( $rule['field'] ?? '' ), $variable ? (string) ( $rule['value'] ?? '' ) : '' ] : [];
                if ( array_filter( $keys, fn( string $key ) => self::missing_field( $key, $missing['fields'] ) ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * A Pro field, also through a relation (`order.customer.total_spent`).
     *
     * @param string   $path
     * @param string[] $fields
     */
    private static function missing_field( string $path, array $fields ): bool {
        foreach ( $fields as $field ) {
            if ( $path === $field || substr( $path, -strlen( ".{$field}" ) ) === ".{$field}" ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $config
     * @return string[] Every text value, nested ones too.
     */
    private static function texts( array $config ): array {
        $texts = [];
        array_walk_recursive(
            $config,
            function ( $value ) use ( &$texts ) {
                if ( is_string( $value ) && false !== strpos( $value, '{{' ) ) {
                    $texts[] = $value;
                }
            }
        );
        return $texts;
    }
}
