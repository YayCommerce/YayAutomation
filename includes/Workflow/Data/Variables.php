<?php
namespace YayAutomation\Workflow\Data;

defined( 'ABSPATH' ) || exit;

/**
 * `{{ order.number }}` merge tags in action text. Spaces inside the braces are optional.
 * Same pattern as `VARIABLE_PATTERN` in the admin app's data module.
 */
final class Variables {

    private const PATTERN = '/\{\{\s*([a-z_]+\.[a-z_]+)\s*\}\}/';

    /**
     * Full keys used in the text, in order, without duplicates.
     *
     * @param string $text Text with merge tags.
     */
    public static function keys( string $text ): array {
        preg_match_all( self::PATTERN, $text, $matches );
        return array_values( array_unique( $matches[1] ) );
    }

    /**
     * Keys used in the text that no data item defines.
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
        $parts = preg_split( self::PATTERN, $text, -1, PREG_SPLIT_DELIM_CAPTURE );
        $out   = '';
        foreach ( $parts as $i => $part ) {
            // preg_split alternates: text, captured key, text, …
            if ( 1 === $i % 2 ) {
                $out .= DataRegistry::display( $part, $context, $html );
            } else {
                $out .= $html ? esc_html( $part ) : $part;
            }
        }
        return $out;
    }
}
