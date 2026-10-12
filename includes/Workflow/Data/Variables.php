<?php
namespace YayAutomation\Workflow\Data;

defined( 'ABSPATH' ) || exit;

/**
 * The paths a text's merge tags use, for `Action::uses()`. Rendering is `Template` over a `Scope`;
 * merge tags are checked by the workflow Validator (paths, filters, what each step can reach).
 */
final class Variables {

    /** @var Template|null */
    private static $template = null;

    /**
     * Paths used in the text, in order, without duplicates.
     *
     * @param string $text Text with merge tags.
     */
    public static function keys( string $text ): array {
        return self::template()->paths( $text );
    }

    private static function template(): Template {
        if ( null === self::$template ) {
            self::$template = new Template();
        }
        return self::$template;
    }
}
