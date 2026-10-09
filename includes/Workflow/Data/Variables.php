<?php
namespace YayAutomation\Workflow\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Merge tags for steps that still get the run context (data item id => object): a bridge to
 * `Template` and `Scope` until steps take a Scope. `{{ order.number }}` renders as before.
 *
 * Checks still know only `item.field` tags: `unknown()` reports any other path (relations,
 * `steps.*`), so a step can't use them yet, and the admin app's `VARIABLE_PATTERN`
 * (apps/admin-page/src/routes/workflows/steps/core/data.ts) matches only those tags. Both move to
 * the Template syntax with the step catalog.
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
     * Paths used in the text that no data item defines.
     *
     * @param string $text Text with merge tags.
     */
    public static function unknown( string $text ): array {
        return array_values( array_filter( self::keys( $text ), fn( string $key ) => ! DataRegistry::field( $key ) ) );
    }

    /**
     * Replaces merge tags with values from the context.
     *
     * @param string $text    Text with merge tags.
     * @param array  $context Run context.
     * @param bool   $html    Return safe HTML: the text around the tags is escaped too.
     */
    public static function render( string $text, array $context, bool $html = false ): string {
        return self::template()->render( $text, self::scope( $context ), $html );
    }

    /**
     * The context as a Scope: its objects become trigger refs, already loaded; other keys (a status
     * change's `from` / `to`) stay plain values.
     *
     * @param array $context Run context.
     */
    private static function scope( array $context ): Scope {
        $entities = DataRegistry::entities();
        $resolver = new RefResolver();
        $trigger  = [];
        foreach ( $context as $key => $value ) {
            if ( isset( $entities[ $key ] ) && is_object( $value ) ) {
                // Never stored, so the id only has to be unique within this render.
                $ref = new Ref( $key, 'context' );
                $resolver->prime( $ref, $value );
                $value = $ref;
            }
            $trigger[ $key ] = $value;
        }
        return new Scope( [ 'trigger' => $trigger ], $resolver, $entities );
    }

    private static function template(): Template {
        if ( null === self::$template ) {
            self::$template = new Template();
        }
        return self::$template;
    }
}
