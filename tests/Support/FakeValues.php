<?php
namespace YayMatik\Tests\Support;

use YayMatik\Workflow\Data\Values;

/** Values from plain arrays: typed values by path, and display text by path. */
final class FakeValues implements Values {

    /** @var array */
    private $values;

    /** @var array path => [ 'text' => plain, 'html' => safe HTML ] */
    private $texts;

    public function __construct( array $values, array $texts = [] ) {
        $this->values = $values;
        $this->texts  = $texts;
    }

    public function get( string $path ) {
        return $this->values[ $path ] ?? null;
    }

    public function text( string $path, bool $html ): string {
        if ( isset( $this->texts[ $path ] ) ) {
            return $this->texts[ $path ][ $html ? 'html' : 'text' ];
        }
        $value = $this->values[ $path ] ?? '';
        $value = is_scalar( $value ) ? (string) $value : '';
        return $html ? esc_html( $value ) : $value;
    }
}
