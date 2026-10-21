<?php
namespace YayMatik\Workflow\Core\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Builds `Template::render_html()` output: follows where it is in the HTML (text, a tag, an
 * attribute value, a comment) so each value is escaped for where it lands. Reads HTML as
 * `wp_kses_post()` and the rich editor write it: attribute values in quotes, no script or style.
 */
final class HtmlContext {

    private const TEXT         = 'text';
    private const COMMENT      = 'comment';
    private const TAG          = 'tag';
    private const NAME         = 'name';
    private const AFTER_NAME   = 'after_name';
    private const BEFORE_VALUE = 'before_value';
    private const VALUE        = 'value';

    /** Attributes whose whole value goes through `esc_url()`. */
    private const URL_ATTRIBUTES = [ 'href', 'src' ];

    /** A value's HTML that starts like this replaces the paragraph it stands alone in. */
    private const BLOCK_START = '/^\s*<(?:address|article|aside|blockquote|div|dl|figure|footer|h[1-6]|header|hr|ol|p|pre|section|table|ul)\b/i';

    // Private-use characters around a block value until `result()`; never kept in the output.
    private const OPEN  = "\u{E000}";
    private const CLOSE = "\u{E001}";

    /** @var string */
    private $out = '';

    /** @var string */
    private $state = self::TEXT;

    /** @var string Attribute being read, lowercased. */
    private $attribute = '';

    /** @var string Quote of the attribute value; empty when unquoted. */
    private $quote = '';

    /** @var string|null Value of a URL attribute so far; null outside one. */
    private $url = null;

    /** @var bool A value was filled into `$url`. */
    private $url_filled = false;

    /** Whether the next value lands in text, where its HTML display text is used. */
    public function in_text(): bool {
        return self::TEXT === $this->state;
    }

    /**
     * Template text between tags: already safe HTML, kept as it is.
     *
     * @param string $text HTML.
     */
    public function literal( string $text ): void {
        $text   = self::unmark( $text );
        $length = strlen( $text );
        for ( $i = 0; $i < $length; $i++ ) {
            $char = $text[ $i ];
            switch ( $this->state ) {
                case self::TEXT:
                    if ( '<' === $char && '<!--' === substr( $text, $i, 4 ) ) {
                        $this->out  .= '<!--';
                        $i          += 3;
                        $this->state = self::COMMENT;
                        continue 2;
                    }
                    $next = $text[ $i + 1 ] ?? '';
                    if ( '<' === $char && ( '' === $next || '/' === $next || '!' === $next || ctype_alpha( $next ) ) ) {
                        $this->state = self::TAG;
                    }
                    $this->out .= $char;
                    break;
                case self::COMMENT:
                    $this->out .= $char;
                    if ( '>' === $char && '-->' === substr( $this->out, -3 ) ) {
                        $this->state = self::TEXT;
                    }
                    break;
                case self::TAG:
                case self::AFTER_NAME:
                    $this->out .= $char;
                    if ( '>' === $char ) {
                        $this->state = self::TEXT;
                    } elseif ( '=' === $char && self::AFTER_NAME === $this->state ) {
                        $this->state = self::BEFORE_VALUE;
                    } elseif ( '/' === $char ) {
                        $this->state = self::TAG;
                    } elseif ( ! ctype_space( $char ) && '"' !== $char && "'" !== $char ) {
                        $this->state     = self::NAME;
                        $this->attribute = strtolower( $char );
                    }
                    break;
                case self::NAME:
                    $this->out .= $char;
                    if ( '=' === $char ) {
                        $this->state = self::BEFORE_VALUE;
                    } elseif ( '>' === $char ) {
                        $this->state = self::TEXT;
                    } elseif ( '/' === $char ) {
                        $this->state = self::TAG;
                    } elseif ( ctype_space( $char ) ) {
                        $this->state = self::AFTER_NAME;
                    } else {
                        $this->attribute .= strtolower( $char );
                    }
                    break;
                case self::BEFORE_VALUE:
                    if ( '"' === $char || "'" === $char ) {
                        $this->out .= $char;
                        $this->start_value( $char );
                    } elseif ( '>' === $char ) {
                        $this->out  .= $char;
                        $this->state = self::TEXT;
                    } elseif ( ctype_space( $char ) ) {
                        $this->out .= $char;
                    } else {
                        $this->start_value( '' );
                        $this->value_char( $char );
                    }
                    break;
                case self::VALUE:
                    if ( '' !== $this->quote ? $char === $this->quote : ( ctype_space( $char ) || '>' === $char ) ) {
                        $this->end_value();
                        $this->out  .= $char;
                        $this->state = '>' === $char ? self::TEXT : self::TAG;
                    } else {
                        $this->value_char( $char );
                    }
                    break;
            }
        }
    }

    /**
     * A tag's value.
     *
     * @param string $plain Plain text, for attributes.
     * @param string $html  Safe HTML, for text; only read when `in_text()`.
     */
    public function value( string $plain, string $html ): void {
        $plain = self::unmark( $plain );
        switch ( $this->state ) {
            case self::TEXT:
                $html       = self::unmark( $html );
                $this->out .= preg_match( self::BLOCK_START, $html ) ? self::OPEN . $html . self::CLOSE : $html;
                return;
            case self::COMMENT:
                $this->out .= esc_html( $plain );
                return;
            case self::BEFORE_VALUE:
                $this->start_value( '' );
                break;
            case self::VALUE:
                break;
            default:
                // Inside a tag but outside an attribute value: it could only add attributes.
                return;
        }
        if ( null !== $this->url ) {
            // The whole URL as a value stays a URL; a value further in is a part of one (a query arg).
            $this->url       .= '' === trim( $this->url ) ? $plain : rawurlencode( $plain );
            $this->url_filled = true;
            return;
        }
        $escaped = esc_attr( $plain );
        // Unquoted, a space would end the value.
        $this->out .= '' === $this->quote ? (string) preg_replace( '/\s/', '&#32;', $escaped ) : $escaped;
    }

    /** The HTML: block values alone in a paragraph replace it. */
    public function result(): string {
        if ( self::VALUE === $this->state ) {
            $this->end_value();
        }
        $out = (string) preg_replace( '#<p(?:\s[^>]*)?>\s*' . self::OPEN . '(.*?)' . self::CLOSE . '\s*</p>#su', '$1', $this->out );
        return self::unmark( $out );
    }

    /**
     * @param string $quote The value's quote; empty when unquoted.
     */
    private function start_value( string $quote ): void {
        $this->state      = self::VALUE;
        $this->quote      = $quote;
        $this->url        = in_array( $this->attribute, self::URL_ATTRIBUTES, true ) ? '' : null;
        $this->url_filled = false;
    }

    /**
     * @param string $char One character of an attribute value.
     */
    private function value_char( string $char ): void {
        if ( null !== $this->url ) {
            $this->url .= $char;
        } else {
            $this->out .= $char;
        }
    }

    /** A URL with a value in it is escaped as a whole; one without stays as written. */
    private function end_value(): void {
        if ( null !== $this->url ) {
            $this->out .= $this->url_filled ? esc_url( $this->url ) : $this->url;
        }
        $this->url = null;
    }

    /**
     * @param string $text Text.
     */
    private static function unmark( string $text ): string {
        return str_replace( [ self::OPEN, self::CLOSE ], '', $text );
    }
}
