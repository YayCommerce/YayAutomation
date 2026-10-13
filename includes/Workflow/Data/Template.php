<?php
namespace YayAutomation\Workflow\Data;

use YayAutomation\Workflow\Definition\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Merge tags in step text: `{{ path | filter: arg, arg }}`, e.g.
 * `{{ order.customer.first_name | default: "there" }}`. Our own small syntax, no template library.
 *
 * - Path: a data item and its fields, dot-separated (`order.total`, `steps.<step id>.code`).
 * - A tag without filters prints the value's display text (a price with its currency, an option's
 *   label); filters work on the typed value.
 * - A missing value prints empty. HTML output is escaped, except the safe HTML a value's display
 *   text already is. The one exception that leaves: `GoneEntity`, when an entity on a path was
 *   deleted; the executor skips the step for it.
 * - A malformed tag stays as written; `errors()` reports it, and unknown filters, for the validator.
 *
 * This is the only renderer: the admin app only parses (checks and autocomplete), and previews
 * render here.
 */
final class Template {

    // A tag can't hold `{{`, so a stray `{{` doesn't swallow the next tag; with `{{{ x }}}` the
    // inner `{{ x }}` is the tag.
    private const TAG = '/\{\{(?!\{)((?:(?!\{\{).)*?)\}\}/s';

    private const PATH = '/^[a-z_][a-z0-9_]*(?:\.[A-Za-z0-9_-]+)+$/';

    private const FILTER_NAME = '/^[a-z_][a-z0-9_]*$/';

    /** @var Filters */
    private $filters;

    /**
     * @param Filters|null $filters Filter registry; null = the default six.
     */
    public function __construct( ?Filters $filters = null ) {
        $this->filters = $filters ?? new Filters();
    }

    /**
     * Text and tags, in order. A tag: `[ 'type' => 'tag', 'raw', 'path', 'filters' => [ [ 'name', 'args' ] ], 'error' ]`,
     * where `error` is null for a well-formed tag. Text: `[ 'type' => 'text', 'text' ]`.
     *
     * @param string $text Template text.
     */
    public function parse( string $text ): array {
        $parts = preg_split( self::TAG, $text, -1, PREG_SPLIT_DELIM_CAPTURE );
        $out   = [];
        foreach ( $parts as $i => $part ) {
            // preg_split alternates: text, tag body, text, …
            if ( 0 === $i % 2 ) {
                if ( '' !== $part ) {
                    $out[] = [
                        'type' => 'text',
                        'text' => $part,
                    ];
                }
                continue;
            }
            $out[] = self::parse_tag( $part );
        }
        return $out;
    }

    /**
     * Paths of the well-formed tags, in order, without duplicates.
     *
     * @param string $text Template text.
     */
    public function paths( string $text ): array {
        $paths = [];
        foreach ( $this->parse( $text ) as $token ) {
            if ( 'tag' === $token['type'] && null === $token['error'] ) {
                $paths[] = $token['path'];
            }
        }
        return array_values( array_unique( $paths ) );
    }

    /**
     * Problems in the text: malformed tags and unknown filters. One message each.
     *
     * @param string $text Template text.
     */
    public function errors( string $text ): array {
        $errors = [];
        foreach ( $this->parse( $text ) as $token ) {
            if ( 'tag' !== $token['type'] ) {
                continue;
            }
            if ( null !== $token['error'] ) {
                $errors[] = $token['error'];
                continue;
            }
            foreach ( $token['filters'] as $filter ) {
                if ( ! $this->filters->has( $filter['name'] ) ) {
                    $errors[] = Messages::get( 'unknownFilter', $filter['name'], implode( ', ', $this->filters->names() ) );
                }
            }
        }
        return $errors;
    }

    /**
     * Fills in the tags.
     *
     * @param string $text   Template text.
     * @param Values $values Where the data comes from.
     * @param bool   $html   Safe HTML instead of plain text: the text around the tags is escaped too.
     */
    public function render( string $text, Values $values, bool $html = false ): string {
        $out = '';
        foreach ( $this->parse( $text ) as $token ) {
            if ( 'text' === $token['type'] ) {
                $out .= $html ? esc_html( $token['text'] ) : $token['text'];
            } elseif ( null !== $token['error'] ) {
                $out .= $html ? esc_html( $token['raw'] ) : $token['raw'];
            } elseif ( ! $token['filters'] ) {
                $out .= $values->text( $token['path'], $html );
            } else {
                $value = $values->get( $token['path'] );
                foreach ( $token['filters'] as $filter ) {
                    $value = $this->filters->apply( $filter['name'], $value, $filter['args'] );
                }
                $value = self::to_text( $value );
                $out  .= $html ? esc_html( $value ) : $value;
            }
        }
        return $out;
    }

    /**
     * @param string $body What's between `{{` and `}}`.
     */
    private static function parse_tag( string $body ): array {
        $tag = [
            'type'    => 'tag',
            'raw'     => '{{' . $body . '}}',
            'path'    => '',
            'filters' => [],
            'error'   => null,
        ];

        $sections = self::split_outside_quotes( $body, '|' );
        if ( null === $sections ) {
            return [ 'error' => self::malformed( $tag['raw'] ) ] + $tag;
        }
        $path = trim( array_shift( $sections ) );
        if ( ! preg_match( self::PATH, $path ) ) {
            return [ 'error' => self::malformed( $tag['raw'] ) ] + $tag;
        }
        $tag['path'] = $path;

        foreach ( $sections as $section ) {
            list( $name, $arg_text ) = array_pad( explode( ':', $section, 2 ), 2, null );
            $name                    = trim( $name );
            $args                    = null === $arg_text ? [] : self::parse_args( $arg_text );
            if ( ! preg_match( self::FILTER_NAME, $name ) || null === $args ) {
                return [ 'error' => self::malformed( $tag['raw'] ) ] + $tag;
            }
            $tag['filters'][] = [
                'name' => $name,
                'args' => $args,
            ];
        }
        return $tag;
    }

    /**
     * `"text", 'text', 3, 1.5` → values. Null when an argument isn't a quoted string or a number.
     *
     * @param string $text Arguments after `name:`.
     */
    private static function parse_args( string $text ): ?array {
        $pieces = self::split_outside_quotes( $text, ',' );
        if ( null === $pieces ) {
            return null;
        }
        $args = [];
        foreach ( $pieces as $piece ) {
            $piece = trim( $piece );
            if ( preg_match( '/^"((?:[^"\\\\]|\\\\.)*)"$/s', $piece, $match ) || preg_match( "/^'((?:[^'\\\\]|\\\\.)*)'$/s", $piece, $match ) ) {
                $args[] = stripcslashes( $match[1] );
            } elseif ( is_numeric( $piece ) ) {
                $args[] = $piece + 0;
            } else {
                return null;
            }
        }
        return $args;
    }

    /**
     * Splits on a one-character separator outside quotes. Null when a quote isn't closed.
     *
     * @param string $text      Text.
     * @param string $separator One character.
     */
    private static function split_outside_quotes( string $text, string $separator ): ?array {
        $pieces  = [];
        $current = '';
        $quote   = null;
        $length  = strlen( $text );
        for ( $i = 0; $i < $length; $i++ ) {
            $char = $text[ $i ];
            if ( null !== $quote ) {
                $current .= $char;
                if ( '\\' === $char && $i + 1 < $length ) {
                    $current .= $text[ ++$i ];
                } elseif ( $char === $quote ) {
                    $quote = null;
                }
            } elseif ( '"' === $char || "'" === $char ) {
                $quote    = $char;
                $current .= $char;
            } elseif ( $char === $separator ) {
                $pieces[] = $current;
                $current  = '';
            } else {
                $current .= $char;
            }
        }
        if ( null !== $quote ) {
            return null;
        }
        $pieces[] = $current;
        return $pieces;
    }

    /**
     * @param string $raw The tag as written.
     */
    private static function malformed( string $raw ): string {
        return Messages::get( 'malformedTag', $raw );
    }

    /**
     * A value as text: lists joined with commas, yes/no for booleans, nothing for other values.
     *
     * @param mixed $value Value.
     */
    public static function to_text( $value ): string {
        if ( null === $value ) {
            return '';
        }
        if ( is_bool( $value ) ) {
            return $value ? __( 'Yes', 'yay-automation' ) : __( 'No', 'yay-automation' );
        }
        if ( is_scalar( $value ) ) {
            return (string) $value;
        }
        if ( is_array( $value ) ) {
            $items = array_filter( $value, 'is_scalar' );
            return implode( ', ', array_map( 'strval', $items ) );
        }
        return '';
    }
}
