<?php
namespace YayAutomation\Workflow\Extend;

defined( 'ABSPATH' ) || exit;

/**
 * Plugins that provide steps: who a step comes from, shown with its logo in the pickers and on
 * template cards. Only active plugins are offered.
 *
 * Each plugin has one logo: our own simple line drawing that marks the plugin, never a copy of
 * its official mark. Drawn with `currentColor` only, so it follows the theme; cleaned with
 * `wp_kses()` and `LOGO_TAGS` before it reaches the page.
 */
final class Plugins {

    public const WOOCOMMERCE = 'woocommerce';

    /** Steps built into Workflow Core itself, e.g. Condition. */
    public const YAYAUTOMATION = 'yayautomation';

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

    /** Opening tag every logo shares: 24×24, outline in the current text color. */
    private const SVG_OPEN = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';

    /** Shape must match `PluginMeta` in apps/admin-page/src/types/localize.d.ts. */
    public static function to_client(): array {
        return [
            [
                'id'          => self::WOOCOMMERCE,
                'label'       => __( 'WooCommerce', 'yay-automation' ),
                'description' => __( 'Orders, customers and store emails.', 'yay-automation' ),
                'active'      => class_exists( 'WooCommerce' ),
                'logo'        => self::clean( self::logos()[ self::WOOCOMMERCE ] ),
            ],
            [
                'id'          => self::YAYAUTOMATION,
                'label'       => __( 'YayAutomation', 'yay-automation' ),
                'description' => __( 'Workflow logic: conditions and waits.', 'yay-automation' ),
                'active'      => true,
                'logo'        => self::clean( self::logos()[ self::YAYAUTOMATION ] ),
            ],
        ];
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

    /**
     * Logo SVG by plugin id, before cleaning.
     *
     * @return string[]
     */
    public static function logos(): array {
        return [
            // A plain shopping bag: a store, not WooCommerce's own mark.
            self::WOOCOMMERCE   => self::SVG_OPEN . '<path d="M5.5 8.5h13l-1 11h-11z" /><path d="M9 8.5V7a3 3 0 0 1 6 0v1.5" /></svg>',
            // Three steps joined: one that starts, two it leads to.
            self::YAYAUTOMATION => self::SVG_OPEN . '<rect x="9" y="3" width="6" height="5" rx="1.5" /><circle cx="6.5" cy="18" r="2.5" /><circle cx="17.5" cy="18" r="2.5" /><path d="M12 8v3.5M6.5 15.5v-4h11v4" /></svg>',
        ];
    }
}
