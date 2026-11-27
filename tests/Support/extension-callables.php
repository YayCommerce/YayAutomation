<?php
/**
 * Callables an extension may name by string: a function and a static method.
 *
 * @package YayMatik
 */

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter, Universal.Files.SeparateFunctionsFromOO.Mixed, Squiz.Classes.ClassFileName.NoMatch -- the handle signature, in one fixture file.

/**
 * @param array $config
 * @param mixed $step
 */
function yaymatik_test_extension_handle( array $config, $step ) {
    return $step->done( [ 'via' => 'function' ] );
}

/** Holds a static `handle` and a method. */
final class YayMatik_Test_Extension_Callables {

    /**
     * @param array $config
     * @param mixed $step
     */
    public static function handle( array $config, $step ) {
        return $step->done( [ 'via' => 'static' ] );
    }

    /**
     * @param array $config
     * @param mixed $step
     */
    public function method( array $config, $step ) {
        return $step->done( [ 'via' => 'method' ] );
    }
}
