<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Workflow\Core\Definition\GraphSchema;
use YayMatik\Workflow\Core\Definition\Validator;
use YayMatik\Workflow\ModuleRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Which extension templates Browse templates hides, with why: the same checks as Use template and Activate.
 *
 * @internal
 */
final class TemplateCheck {

    /** Plugin ids of core's public steps (docs/extension-api.md §12). */
    private const CORE_PLUGINS = [ 'yaymatik', 'woocommerce' ];

    /** Issues that leave a template shown: Use template makes the draft, Activate waits for the setup. */
    private const SETUP_CODES = [ 'setup_issue', 'cart_tracking_off' ];

    /**
     * Template id => why it's hidden; also a diagnostic each.
     *
     * @param ExtensionModule[] $modules
     */
    public static function hidden( array $modules ): array {
        // In a request these are the registry's own modules; ids it has already win.
        $plugins = ModuleRegistry::plugins( ModuleRegistry::modules() + array_combine( array_map( fn( ExtensionModule $module ) => $module->id(), $modules ), $modules ) );
        $hidden  = [];
        foreach ( $modules as $module ) {
            foreach ( $module->templates() as $id => $args ) {
                $reason = self::reason( $module->id(), $args, $plugins );
                if ( null !== $reason ) {
                    $hidden[ $id ] = $reason;
                    Declaration::refuse( $module->id(), "template {$id}", 'Hidden from Browse templates: ' . $reason );
                }
            }
        }
        return $hidden;
    }

    /**
     * Why the template can't be offered, or null.
     *
     * @param string   $module
     * @param array    $args    From `Declaration::template()`.
     * @param string[] $plugins Plugin id by step id.
     */
    public static function reason( string $module, array $args, array $plugins ): ?string {
        $graph = TemplateBuilder::graph( $args['steps'] );
        if ( is_string( $graph ) ) {
            return $graph;
        }
        foreach ( $graph['nodes'] as $node ) {
            $owner = $plugins[ $node['data']['block'] ] ?? '';
            if ( $owner !== $module && ! in_array( $owner, self::CORE_PLUGINS, true ) ) {
                return "Step \"{$node['id']}\" is another plugin's ({$node['data']['block']}): a template uses core steps and its own module's only.";
            }
        }
        $prepared = GraphSchema::prepare( $graph );
        if ( is_wp_error( $prepared ) ) {
            return $prepared->get_error_message();
        }
        $configs = [];
        foreach ( $graph['nodes'] as $node ) {
            $configs[ $node['id'] ] = $node['data']['config'];
        }
        foreach ( Validator::errors(
            [
                'name'  => $args['name'],
                'graph' => $prepared,
            ]
        ) as $issue ) {
            if ( ! self::needs_setup( $issue, $configs ) ) {
                return isset( $issue['nodeId'] ) ? "Step \"{$issue['nodeId']}\": {$issue['message']}" : $issue['message'];
            }
        }
        return null;
    }

    /**
     * A setup issue, or a field the template left blank for the store owner to fill.
     *
     * @param array   $issue
     * @param array[] $configs The template's own configs by node id.
     */
    private static function needs_setup( array $issue, array $configs ): bool {
        if ( in_array( $issue['code'], self::SETUP_CODES, true ) ) {
            return true;
        }
        return 'invalid_config' === $issue['code'] && isset( $issue['field'], $issue['nodeId'] ) && empty( $configs[ $issue['nodeId'] ][ $issue['field'] ] );
    }
}
