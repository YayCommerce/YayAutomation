<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Workflow\Core\Data\GoneEntity;

defined( 'ABSPATH' ) || exit;

/**
 * Runs an extension's callback: a throw becomes a diagnostic and the fallback's value. A deleted
 * entity is not an error: `GoneEntity` goes on to the Executor, which skips the step.
 *
 * @internal
 */
final class Guard {

    /**
     * @param string   $module
     * @param string   $callback
     * @param callable $callback_fn
     * @param callable $fallback Gets the throwable; its value is returned.
     * @return mixed
     */
    public static function call( string $module, string $callback, callable $callback_fn, callable $fallback ) {
        try {
            return $callback_fn();
        } catch ( GoneEntity $gone ) {
            throw $gone;
        } catch ( \Throwable $e ) {
            Diagnostics::record( $module, $callback, $e->getMessage() );
            return $fallback( $e );
        }
    }
}
