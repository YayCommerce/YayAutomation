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
        $pro_steps = ProCatalog::missing()['steps'];
        $recorded  = Diagnostics::recorded();
        $problems  = [];
        $notes     = [];
        foreach ( $recorded as $entry ) {
            // Only the first step a chain can't build is named: the rest is checked on a site with Pro.
            if ( preg_match( '/^Hidden from Browse templates: Step "[^"]+": unknown step "([^"]+)"\.$/', $entry['message'], $match ) && in_array( $match[1], $pro_steps, true ) ) {
                $notes[ $entry['module'] ][] = "{$entry['callback']}: needs YayMatik Pro (step \"{$match[1]}\"), so hidden on this site; check it on a site with Pro.";
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
        if ( Diagnostics::cut() ) {
            \WP_CLI::warning( sprintf( 'Only the first %d problems of this request are listed.', Diagnostics::KEEP ) );
            ++$count;
        }
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
}
