<?php
namespace YayMatik\Tests\Support;

use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\DataRegistry;

/**
 * Merge tags as v0.1 rendered them (`Data/Variables` before Template), frozen as the oracle: Template + Scope must
 * render every old `{{ item.field }}` tag the same. Reads the run context (data item id => object).
 */
final class LegacyVariables {

    private const PATTERN = '/\{\{\s*([a-z_]+\.[a-z_]+)\s*\}\}/';

    public static function keys( string $text ): array {
        preg_match_all( self::PATTERN, $text, $matches );
        return array_values( array_unique( $matches[1] ) );
    }

    public static function render( string $text, array $context, bool $html = false ): string {
        $parts = preg_split( self::PATTERN, $text, -1, PREG_SPLIT_DELIM_CAPTURE );
        $out   = '';
        foreach ( $parts as $i => $part ) {
            if ( 1 === $i % 2 ) {
                $out .= self::display( $part, $context, $html );
            } else {
                $out .= $html ? esc_html( $part ) : $part;
            }
        }
        return $out;
    }

    /**
     * The removed v0.1 `DataRegistry::display()`: empty when the field is unknown or the context lacks its item.
     *
     * @param string $key     Full field key.
     * @param array  $context Run context.
     * @param bool   $html    Escaped HTML instead of plain text.
     */
    private static function display( string $key, array $context, bool $html ): string {
        $field = DataRegistry::field( $key );
        $item  = DataRegistry::item_of( $key );
        if ( ! $field || ( ! in_array( $item, DataRegistry::ALWAYS, true ) && ! isset( $context[ $item ] ) ) ) {
            return '';
        }
        return DataItem::display( $field, $context[ $item ] ?? null, $html );
    }
}
