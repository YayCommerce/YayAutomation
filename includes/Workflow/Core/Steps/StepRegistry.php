<?php
namespace YayMatik\Workflow\Core\Steps;

use YayMatik\Workflow\Extension\Extensions;
use YayMatik\Workflow\ModuleRegistry;

defined( 'ABSPATH' ) || exit;

/** Every step the modules register, by id. */
final class StepRegistry {

    /** @var Step[]|null By step id. */
    private static $steps = null;

    /** @return Step[] */
    public static function all(): array {
        if ( null !== self::$steps ) {
            return self::$steps;
        }
        $steps = ModuleRegistry::steps( ModuleRegistry::modules() );
        // Read from a `yaymatik_register` callback: other plugins' modules are still missing, so not kept.
        if ( ! Extensions::registering() ) {
            self::$steps = $steps;
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
