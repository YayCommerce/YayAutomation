<?php
namespace YayMatik\Workflow\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the public API objects. Their constructors are private on purpose: every public method
 * is part of the frozen API.
 *
 * @internal
 */
final class Access {

    /**
     * @param string $class_name
     * @param mixed  ...$args
     * @return object
     */
    public static function make( string $class_name, ...$args ) {
        // A closure bound to the class's scope may call its private constructor.
        return \Closure::bind( static fn( array $args ) => new $class_name( ...$args ), null, $class_name )( $args );
    }
}
