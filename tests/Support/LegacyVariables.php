<?php
namespace YayAutomation\Tests\Support;

use YayAutomation\Workflow\Data\DataRegistry;

/** `Data/Variables` as it was before the Template bridge, kept to prove the bridge renders the same. */
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
                $out .= DataRegistry::display( $part, $context, $html );
            } else {
                $out .= $html ? esc_html( $part ) : $part;
            }
        }
        return $out;
    }
}
