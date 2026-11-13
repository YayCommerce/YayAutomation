<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Api\Module;
use YayMatik\Api\Registrar;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the `yaymatik_register` callbacks once and collects the modules they register.
 *
 * @internal
 */
final class Extensions {

    public const HOOK = 'yaymatik_register';

    /** @var string idle | open | done */
    private static $state = 'idle';

    /** Whether the hook is running now. */
    public static function registering(): bool {
        return 'open' === self::$state;
    }

    /**
     * Empty after the first call.
     *
     * @param string[] $taken Ids of the modules core already has.
     * @return ExtensionModule[]
     */
    public static function register( array $taken ): array {
        if ( 'idle' !== self::$state ) {
            return [];
        }
        self::$state = 'open';
        try {
            return self::collect( $taken, ...self::callbacks() );
        } finally {
            self::$state = 'done';
        }
    }

    /**
     * The modules the callbacks register, each callback on its own: one that throws leaves the
     * others' modules in. The registrar and every handle refuse calls once they have run.
     *
     * @param string[] $taken
     * @param callable ...$callbacks
     * @return ExtensionModule[]
     */
    public static function collect( array $taken, callable ...$callbacks ): array {
        Declaration::begin();
        $open      = true;
        $is_open   = function () use ( &$open ) {
            return $open;
        };
        $modules   = [];
        $registrar = Access::make(
            Registrar::class,
            function ( array $args ) use ( &$modules, $taken, $is_open ) {
                $module = null;
                if ( $is_open() ) {
                    $module = Declaration::module( $args, array_merge( $taken, array_keys( $modules ) ), $is_open );
                } else {
                    Declaration::too_late( is_string( $args['id'] ?? null ) ? $args['id'] : '(unknown)', 'module' );
                }
                if ( $module ) {
                    $modules[ $module->id() ] = $module;
                }
                return Access::make( Module::class, $module );
            }
        );
        foreach ( $callbacks as $callback ) {
            $before = count( $modules );
            try {
                $callback( $registrar );
            } catch ( \Throwable $e ) {
                // Named by the module the callback registered before it threw, if any.
                $own = array_slice( array_keys( $modules ), $before );
                Diagnostics::record( $own ? end( $own ) : '(unknown)', 'register', $e->getMessage() );
            }
        }
        $open = false;
        return array_values( $modules );
    }

    /**
     * The hook's callbacks in priority order. Called one by one instead of `do_action()`, which
     * stops at the first throw.
     *
     * @return callable[]
     */
    private static function callbacks(): array {
        global $wp_filter;
        $callbacks = [];
        foreach ( $wp_filter[ self::HOOK ]->callbacks ?? [] as $at_priority ) {
            foreach ( $at_priority as $callback ) {
                if ( is_callable( $callback['function'] ) ) {
                    $callbacks[] = $callback['function'];
                }
            }
        }
        return $callbacks;
    }
}
