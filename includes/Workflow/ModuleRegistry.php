<?php
namespace YayMatik\Workflow;

use YayMatik\Pro\Workflow\Modules\WoocommerceProModule;
use YayMatik\Pro\Workflow\Modules\YayMatikProModule;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Modules\Module;
use YayMatik\Workflow\Modules\WoocommerceModule;
use YayMatik\Workflow\Modules\YayMatikModule;
use YayMatik\Workflow\Core\Steps\Action;
use YayMatik\Workflow\Core\Steps\Condition;
use YayMatik\Workflow\Core\Steps\Step;
use YayMatik\Workflow\Core\Steps\Trigger;
use YayMatik\Workflow\Extension\Diagnostics;
use YayMatik\Workflow\Extension\Extensions;

defined( 'ABSPATH' ) || exit;

/**
 * The modules, and the steps and data items they register. Other plugins register on
 * `yaymatik_register` (`Extension\Extensions`); Pro adds module classes on `HOOK`. An id that is already taken (module, step or data item) keeps its first owner; the
 * second claim is a diagnostic.
 */
final class ModuleRegistry {

    public const HOOK = 'yaymatik_modules';

    /** @var Module[]|null */
    private static $modules = null;

    /** @var array|null */
    private static $built = null;

    /** Module classes: the Pro ones when the Pro folder ships, then the filter's. */
    public static function classes(): array {
        $modules = class_exists( YayMatikProModule::class ) ? [ YayMatikProModule::class, WoocommerceProModule::class ] : [ YayMatikModule::class, WoocommerceModule::class ];
        return (array) apply_filters( self::HOOK, $modules );
    }

    /** @return Module[] Keyed by id. The first call fires `yaymatik_register`. */
    public static function modules(): array {
        if ( null !== self::$modules ) {
            return self::$modules;
        }
        // Read from a `yaymatik_register` callback: what is there so far, not kept.
        if ( Extensions::registering() ) {
            return self::from( self::classes() );
        }
        $core          = self::from( self::classes() );
        self::$modules = self::from( array_merge( array_values( $core ), Extensions::register( array_keys( $core ) ) ) );
        return self::$modules;
    }

    /**
     * @param array $classes Module class names, or modules.
     * @return Module[] Keyed by id; anything that isn't a Module subclass is left out.
     */
    public static function from( array $classes ): array {
        $modules = [];
        foreach ( $classes as $class ) {
            if ( $class instanceof Module ) {
                $module = $class;
            } elseif ( is_string( $class ) && is_subclass_of( $class, Module::class ) ) {
                $module = new $class();
            } else {
                continue;
            }
            if ( isset( $modules[ $module->id() ] ) ) {
                // Named by its class: the id is the winner's.
                self::taken( get_class( $module ), 'module', $module->id() );
                continue;
            }
            $modules[ $module->id() ] = $module;
        }
        return $modules;
    }

    /**
     * @param Module[] $modules
     * @return Step[] Keyed by id.
     */
    public static function steps( array $modules ): array {
        return self::build( $modules )[0];
    }

    /**
     * @param Module[] $modules
     * @return string[] Module id by step id.
     */
    public static function plugins( array $modules ): array {
        return self::build( $modules )[1];
    }

    /**
     * @param Module[] $modules
     * @return DataItem[] Keyed by id.
     */
    public static function data( array $modules ): array {
        $items = [];
        foreach ( $modules as $module ) {
            foreach ( $module->data_items() as $class ) {
                if ( ! is_subclass_of( $class, DataItem::class ) ) {
                    continue;
                }
                $item = is_string( $class ) ? new $class() : $class;
                if ( isset( $items[ $item->id() ] ) ) {
                    self::taken( $module->id(), 'data item', $item->id() );
                    continue;
                }
                $items[ $item->id() ] = $item;
            }
        }
        return $items;
    }

    /** Plugin entries. Shape must match `PluginMeta` in apps/admin-page/src/types/localize.d.ts. */
    public static function to_client(): array {
        return array_values(
            array_map(
                fn( Module $module ) => [
                    'id'          => $module->id(),
                    'label'       => $module->label(),
                    'description' => $module->description(),
                    'active'      => $module->active(),
                    // Core's logos are `data:` SVGs; only that kind of data URI is let through.
                    'logo'        => esc_url_raw( $module->logo(), 0 === strpos( $module->logo(), 'data:image/svg+xml,' ) ? [ 'data' ] : [ 'http', 'https' ] ),
                ],
                self::modules()
            )
        );
    }

    /**
     * Steps keyed by id and the module that registered each. A class listed under the wrong kind is left out.
     *
     * @param Module[] $modules
     */
    private static function build( array $modules ): array {
        if ( null === self::$modules || $modules !== self::$modules ) {
            return self::collect( $modules );
        }
        if ( null === self::$built ) {
            self::$built = self::collect( $modules );
        }
        return self::$built;
    }

    /** @param Module[] $modules */
    private static function collect( array $modules ): array {
        $steps   = [];
        $plugins = [];
        foreach ( $modules as $module ) {
            $lists = [
                Trigger::class   => $module->triggers(),
                Condition::class => $module->conditions(),
                Action::class    => $module->actions(),
            ];
            foreach ( $lists as $kind => $classes ) {
                foreach ( $classes as $class ) {
                    if ( ! is_subclass_of( $class, $kind ) ) {
                        continue;
                    }
                    $step = is_string( $class ) ? new $class() : $class;
                    if ( isset( $steps[ $step->id() ] ) ) {
                        self::taken( $module->id(), 'step', $step->id() );
                        continue;
                    }
                    $steps[ $step->id() ]   = $step;
                    $plugins[ $step->id() ] = $module->id();
                }
            }
        }
        return [ $steps, $plugins ];
    }

    /**
     * @param string $module
     * @param string $what
     * @param string $id
     */
    private static function taken( string $module, string $what, string $id ): void {
        Diagnostics::record( $module, "{$what} {$id}", sprintf( '%s id "%s" is already taken by another module; this one was left out.', ucfirst( $what ), $id ) );
    }
}
