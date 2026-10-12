<?php
namespace YayAutomation\Workflow\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Merge tags for steps that still get the run context (data item id => object): a bridge to
 * `Template` and `Scope::from_context()` until steps take a Scope. `{{ order.number }}` renders as before.
 *
 * Merge tags are checked by the workflow Validator (paths, filters, what each step can reach).
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

    /**
     * Replaces merge tags with values from the context.
     *
     * @param string $text    Text with merge tags.
     * @param array  $context Run context.
     * @param bool   $html    Return safe HTML: the text around the tags is escaped too.
     */
    public static function render( string $text, array $context, bool $html = false ): string {
        return self::template()->render( $text, Scope::from_context( $context ), $html );
    }

    private static function template(): Template {
        if ( null === self::$template ) {
            self::$template = new Template();
        }
        return self::$template;
    }
}
