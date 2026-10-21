<?php
namespace YayMatik\Workflow\Steps\Core;

use YayMatik\Workflow\Extend\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Every step Workflow Core knows, by id: the steps of the modules that are on (see
 * Workflow/Extend/Modules). To add a step, write its class and list it under its module there.
 */
final class StepRegistry {

    /** @var Step[]|null Keyed by step id. */
    private static $steps = null;

    /** @return Step[] */
    public static function all(): array {
        if ( null === self::$steps ) {
            self::$steps = self::from_manifest( Modules::manifest() );
        }
        return self::$steps;
    }

    /**
     * The steps of the modules that are on, keyed by id, in manifest order.
     *
     * @param array[] $manifest Shape of `Modules::manifest()`.
     * @return Step[]
     */
    public static function from_manifest( array $manifest ): array {
        $steps = [];
        foreach ( $manifest as $module ) {
            if ( empty( $module['on'] ) ) {
                continue;
            }
            foreach ( $module['steps'] ?? [] as $class ) {
                $step                 = new $class();
                $steps[ $step->id() ] = $step;
            }
        }
        return $steps;
    }

    public static function get( string $id ): ?Step {
        return self::all()[ $id ] ?? null;
    }

    /** @return Trigger[] */
    public static function triggers(): array {
        return array_filter( self::all(), fn( Step $step ) => $step instanceof Trigger );
    }
}
