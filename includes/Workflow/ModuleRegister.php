<?php
namespace YayMatik\Workflow;

use YayMatik\Workflow\Data\DataItem;
use YayMatik\Workflow\Modules\MatikModule;
use YayMatik\Workflow\Modules\ModuleInterface;
use YayMatik\Workflow\Modules\WoocommerceModule;
use YayMatik\Workflow\Steps\Core\Step;

defined( 'ABSPATH' ) || exit;

/**
 * The modules that are on, and what they add.
 *
 * `StepRegistry`, `DataRegistry` and the catalog read from here. Another plugin adds a module
 * through the filter, from its main file:
 *
 *     add_filter( 'yaymatik_modules', fn( $modules ) => array_merge( $modules, [ new MyModule() ] ) );
 *
 * Read once, on first use (`plugins_loaded`). Core's modules come first, so an id that is
 * already taken (module, step or data item) keeps its first owner.
 */
final class ModuleRegister {

    public const HOOK = 'yaymatik_modules';

    /** Tags and attributes a logo may use: lines and simple shapes, no styles, links or scripts. */
    public const LOGO_TAGS = [
        'svg'    => [
            'xmlns'           => true,
            'viewbox'         => true,
            'fill'            => true,
            'stroke'          => true,
            'stroke-width'    => true,
            'stroke-linecap'  => true,
            'stroke-linejoin' => true,
            'aria-hidden'     => true,
            'focusable'       => true,
        ],
        'path'   => [ 'd' => true ],
        'circle' => [
            'cx' => true,
            'cy' => true,
            'r'  => true,
        ],
        'rect'   => [
            'x'      => true,
            'y'      => true,
            'width'  => true,
            'height' => true,
            'rx'     => true,
        ],
    ];

    /** @var ModuleInterface[]|null Keyed by id. */
    private static $modules = null;

    /** @return ModuleInterface[] Modules that are on, keyed by id. */
    public static function modules(): array {
        if ( null === self::$modules ) {
            self::$modules = [];
            $modules       = apply_filters( self::HOOK, [ new WoocommerceModule(), new MatikModule() ] );
            foreach ( (array) $modules as $module ) {
                if ( $module instanceof ModuleInterface && $module->enabled() && ! isset( self::$modules[ $module->id() ] ) ) {
                    self::$modules[ $module->id() ] = $module;
                }
            }
        }
        return self::$modules;
    }

    /**
     * Steps by id, in module order.
     *
     * @param ModuleInterface[]|null $modules Null = `modules()`.
     * @return Step[]
     */
    public static function steps( ?array $modules = null ): array {
        $steps = [];
        foreach ( $modules ?? self::modules() as $module ) {
            foreach ( $module->steps() as $class ) {
                $step = new $class();
                if ( $step instanceof Step && ! isset( $steps[ $step->id() ] ) ) {
                    $steps[ $step->id() ] = $step;
                }
            }
        }
        return $steps;
    }

    /**
     * Data items by id, in module order.
     *
     * @param ModuleInterface[]|null $modules Null = `modules()`.
     * @return DataItem[]
     */
    public static function items( ?array $modules = null ): array {
        $items = [];
        foreach ( $modules ?? self::modules() as $module ) {
            foreach ( $module->data_items() as $item ) {
                if ( $item instanceof DataItem && ! isset( $items[ $item->id() ] ) ) {
                    $items[ $item->id() ] = $item;
                }
            }
        }
        return $items;
    }

    /** Plugin entries. Shape must match `PluginMeta` in apps/admin-page/src/types/localize.d.ts. */
    public static function to_client(): array {
        return array_values(
            array_map(
                fn( ModuleInterface $module ) => [
                    'id'          => $module->id(),
                    'label'       => $module->label(),
                    'description' => $module->description(),
                    'active'      => $module->active(),
                    'logo'        => self::clean( $module->logo() ),
                ],
                self::modules()
            )
        );
    }

    /**
     * A logo through `wp_kses()` with `LOGO_TAGS`. kses lowercases attribute names; `viewBox` gets its
     * case back, since SVG read as XML (an img data URI, DOMParser, a CSS mask) ignores `viewbox`.
     *
     * @param string $svg Logo SVG.
     */
    public static function clean( string $svg ): string {
        return str_replace( ' viewbox=', ' viewBox=', wp_kses( $svg, self::LOGO_TAGS ) );
    }
}
