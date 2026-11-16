<?php
namespace YayMatik\Workflow\Core\Data;

use YayMatik\Workflow\Core\Definition\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Merge tags in step text, `{{ path | filter: arg, arg }}`; the one renderer (the admin app only parses).
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
     * @param Filters|null $filters Null = the default six.
     */
    public function __construct( ?Filters $filters = null ) {
        $this->filters = $filters ?? new Filters();
    }

    /**
     * Text and tags, in order. A tag: `[ 'type' => 'tag', 'raw', 'path', 'filters' => [ [ 'name', 'args' ] ], 'error' ]`,
     * where `error` is null for a well-formed tag. Text: `[ 'type' => 'text', 'text' ]`.
     *
     * @param string $text
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
     * @param string $text
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
     * Names of the `{{ name }}` placeholders in catalog text (a summary, a result line, a required message).
     *
     * @param string $text
     */
    public function placeholders( string $text ): array {
        $names = [];
        foreach ( $this->parse( $text ) as $token ) {
            if ( 'tag' === $token['type'] ) {
                $names[] = self::placeholder_name( $token['raw'] );
            }
        }
        return $names;
    }

    /**
     * Fills `{{ name }}` placeholders in catalog text, never re-parsing a value; TS `fillTemplate()`.
     *
     * @param string               $text
     * @param array<string,string> $values
     */
    public function fill( string $text, array $values ): string {
        $out = '';
        foreach ( $this->parse( $text ) as $token ) {
            if ( 'text' === $token['type'] ) {
                $out .= $token['text'];
                continue;
            }
            $name = self::placeholder_name( $token['raw'] );
            $out .= array_key_exists( $name, $values ) ? $values[ $name ] : $token['raw'];
        }
        return $out;
    }

    /**
     * `{{ config.status }}` → `config.status`, `{{ step }}` → `step`.
     *
     * @param string $raw
     */
    private static function placeholder_name( string $raw ): string {
        return trim( substr( $raw, 2, -2 ) );
    }

    /**
     * Malformed tags and unknown filters, one message each.
     *
     * @param string $text
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
     * Fills in the tags. A missing value prints empty; a malformed tag stays as written.
     *
     * @param string $text
     * @param Values $values
     * @param bool   $html   Safe HTML: the text around the tags is escaped too.
     * @throws GoneEntity When an entity on a path was deleted.
     */
    public function render( string $text, Values $values, bool $html = false ): string {
        $out = '';
        foreach ( $this->parse( $text ) as $token ) {
            if ( 'text' === $token['type'] ) {
                $out .= $html ? esc_html( $token['text'] ) : $token['text'];
            } elseif ( null !== $token['error'] ) {
                $out .= $html ? esc_html( $token['raw'] ) : $token['raw'];
            } else {
                $out .= $this->tag_text( $token, $values, $html );
            }
        }
        return $out;
    }

    /**
     * Fills in the tags of HTML already cleaned by `wp_kses_post()`; each value is escaped for where it lands:
     *
     * - text: `esc_html()`; HTML display text (a coupon box) as it is. A block value alone in a paragraph replaces it.
     * - `href` / `src`: a value starting the URL as it is, one further in `rawurlencode()`d; then `esc_url()` on the whole.
     * - other attributes: `esc_attr()`.
     * - inside a tag, outside any attribute value: dropped.
     * - a malformed tag: as written, escaped like the text around it.
     *
     * @param string $html
     * @param Values $values
     */
    public function render_html( string $html, Values $values ): string {
        $context = new HtmlContext();
        foreach ( $this->parse( $html ) as $token ) {
            if ( 'text' === $token['type'] ) {
                $context->literal( $token['text'] );
                continue;
            }
            if ( null !== $token['error'] ) {
                $context->value( $token['raw'], esc_html( $token['raw'] ) );
                continue;
            }
            $context->value(
                $this->tag_text( $token, $values, false ),
                $context->in_text() ? $this->tag_text( $token, $values, true ) : ''
            );
        }
        return $context->result();
    }

    /**
     * A well-formed tag's text.
     *
     * @param array  $token
     * @param Values $values
     * @param bool   $html
     */
    private function tag_text( array $token, Values $values, bool $html ): string {
        // Without filters, the display text (a price with its currency); filters work on the typed value.
        if ( ! $token['filters'] ) {
            return $values->text( $token['path'], $html );
        }
        $value = $values->get( $token['path'] );
        foreach ( $token['filters'] as $filter ) {
            $value = $this->filters->apply( $filter['name'], $value, $filter['args'] );
        }
        $value = self::to_text( $value );
        return $html ? esc_html( $value ) : $value;
    }

    /**
     * @param string $body Between `{{` and `}}`.
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
     * @param string $text After `name:`.
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
     * @param string $text
     * @param string $separator
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

    private static function malformed( string $raw ): string {
        return Messages::get( 'malformedTag', $raw );
    }

    /**
     * A value as text: lists joined with commas, yes/no for booleans, nothing for other values.
     *
     * @param mixed $value
     */
    public static function to_text( $value ): string {
        if ( null === $value ) {
            return '';
        }
        if ( is_bool( $value ) ) {
            return $value ? __( 'Yes', 'yaymatik' ) : __( 'No', 'yaymatik' );
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
