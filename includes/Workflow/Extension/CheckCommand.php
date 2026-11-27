<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Workflow\Core\Definition\ProCatalog;
use YayMatik\Workflow\ModuleRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * `wp yaymatik extension check`: the problems of every extension's declarations and templates on this site.
 *
 * @internal
 */
final class CheckCommand {

    /**
     * Lists each extension module with its requires_api, then the problems its declarations and templates raise. Exits 1 when there is one.
     *
     * ## OPTIONS
     *
     * [<module>]
     * : Only this module id.
     *
     * ## EXAMPLES
     *
     *     wp yaymatik extension check
     *     wp yaymatik extension check yaymail
     *
     * @param array $args
     */
    public function __invoke( array $args ): void {
        $modules = array_filter( ModuleRegistry::modules(), fn( $module ) => $module instanceof ExtensionModule );
        // Browse templates checks them only when they change; here always.
        TemplateCheck::hidden( array_values( $modules ) );
        $pro_only = self::pro_only( $modules );
        $problems = [];
        $notes    = [];
        foreach ( Diagnostics::recorded() as $entry ) {
            if ( isset( $pro_only[ $entry['module'] ][ $entry['callback'] ] ) ) {
                $notes[ $entry['module'] ][] = "{$entry['callback']}: needs YayMatik Pro, so hidden on this site.";
            } else {
                $problems[ $entry['module'] ][] = "{$entry['callback']}: {$entry['message']}";
            }
        }
        $ids = array_unique( array_merge( array_keys( $modules ), array_keys( $problems ), array_keys( $notes ) ) );
        sort( $ids );
        if ( isset( $args[0] ) ) {
            if ( ! in_array( $args[0], $ids, true ) ) {
                \WP_CLI::error( "No extension module \"{$args[0]}\" on this site." );
            }
            $ids = [ $args[0] ];
        }
        if ( ! $ids ) {
            \WP_CLI::success( 'No extension modules on this site.' );
            return;
        }
        $count = 0;
        foreach ( $ids as $id ) {
            $module = $modules[ $id ] ?? null;
            \WP_CLI::log( $module ? "{$id} ({$module->label()}), requires_api " . ( $module->requires_api() ?? 'not set' ) : "{$id}: not registered" );
            foreach ( $problems[ $id ] ?? [] as $problem ) {
                \WP_CLI::log( "  - {$problem}" );
            }
            foreach ( $notes[ $id ] ?? [] as $note ) {
                \WP_CLI::log( "  * {$note}" );
            }
            if ( ! isset( $problems[ $id ] ) ) {
                \WP_CLI::log( '  ok' );
            }
            $count += count( $problems[ $id ] ?? [] );
        }
        if ( $count ) {
            \WP_CLI::error( sprintf( '%d problem(s).', $count ) );
        }
        \WP_CLI::success( sprintf( '%d module(s), no problems.', count( $ids ) ) );
    }

    /**
     * Templates hidden here only because they use a step of YayMatik Pro, which this site lacks: `template <id>` by module id.
     *
     * @param ExtensionModule[] $modules
     */
    private static function pro_only( array $modules ): array {
        $pro_steps = ProCatalog::missing()['steps'];
        $pro_only  = [];
        foreach ( $modules as $module ) {
            foreach ( $module->templates() as $id => $args ) {
                if ( array_intersect( self::step_ids( $args['steps'] ), $pro_steps ) ) {
                    $pro_only[ $module->id() ][ "template {$id}" ] = true;
                }
            }
        }
        return $pro_only;
    }

    /**
     * The step ids a template's chain names, branches included; read as written, as a chain with an unknown step makes no graph.
     *
     * @param array $steps
     */
    private static function step_ids( array $steps ): array {
        $ids = [];
        foreach ( $steps as $item ) {
            $ids[] = is_array( $item ) && is_string( $item['step'] ?? null ) ? $item['step'] : '';
            foreach ( [ 'then', 'otherwise' ] as $branch ) {
                if ( is_array( $item[ $branch ] ?? null ) ) {
                    $ids = array_merge( $ids, self::step_ids( $item[ $branch ] ) );
                }
            }
        }
        return $ids;
    }
}
