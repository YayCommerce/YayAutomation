<?php
/**
 * Callables an extension may name by string: a function and a static method.
 *
 * @package YayMatik
 */

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter, Universal.Files.SeparateFunctionsFromOO.Mixed, Squiz.Classes.ClassFileName.NoMatch -- the run signature, in one fixture file.

/**
 * @param array $config
 * @param mixed $run
 */
function yaymatik_test_extension_run( array $config, $run ) {
    return $run->done( [ 'via' => 'function' ] );
}

/** Holds a static `run` and a method. */
final class YayMatik_Test_Extension_Callables {

    /**
     * @param array $config
     * @param mixed $run
     */
    public static function run( array $config, $run ) {
        return $run->done( [ 'via' => 'static' ] );
    }

    /**
     * @param array $config
     * @param mixed $run
     */
    public function method( array $config, $run ) {
        return $run->done( [ 'via' => 'method' ] );
    }
}
