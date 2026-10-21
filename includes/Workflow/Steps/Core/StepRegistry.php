<?php
namespace YayMatik\Workflow\Steps\Core;

use YayMatik\Workflow\ModuleRegister;

defined( 'ABSPATH' ) || exit;

/**
 * Every step Workflow Core knows, by id: the steps of the modules that are on (see
 * `ModuleRegister`). To add a step, write its class and list it in its module.
 */
final class StepRegistry {

    /** @var Step[]|null Keyed by step id. */
    private static $steps = null;

    /** @return Step[] */
    public static function all(): array {
        if ( null === self::$steps ) {
            self::$steps = ModuleRegister::steps();
        }
        return self::$steps;
    }

    public static function get( string $id ): ?Step {
        return self::all()[ $id ] ?? null;
    }

    /** @return Trigger[] */
    public static function triggers(): array {
        return array_filter( self::all(), fn( Step $step ) => $step instanceof Trigger );
    }
}
