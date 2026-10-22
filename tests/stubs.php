<?php
/**
 * WordPress functions the tested classes call, like WordPress for the inputs the tests use.
 *
 * @package YayMatik
 */

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as WordPress.
        return $text;
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $hook_name, $callback ) {
        $GLOBALS['yaymatik_test_filters'][ $hook_name ][] = $callback;
        return true;
    }
}

if ( ! function_exists( 'remove_filter' ) ) {
    function remove_filter( $hook_name, $callback ) {
        $GLOBALS['yaymatik_test_filters'][ $hook_name ] = array_filter( $GLOBALS['yaymatik_test_filters'][ $hook_name ] ?? [], fn( $added ) => $added !== $callback );
        return true;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook_name, $value ) {
        foreach ( $GLOBALS['yaymatik_test_filters'][ $hook_name ] ?? [] as $callback ) {
            $value = $callback( $value );
        }
        return $value;
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    // Like WordPress: entities already in the text aren't encoded again.
    function esc_html( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
    }
}

if ( ! function_exists( 'esc_url' ) ) {
    // Like WordPress: spaces encoded, other unsafe characters dropped, only known protocols kept,
    // `http://` added to a bare host, `&` and `'` as entities.
    function esc_url( $url ) {
        $url = str_replace( ' ', '%20', ltrim( (string) $url ) );
        $url = (string) preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\x80-\xff]|i', '', $url );
        if ( '' === $url ) {
            return '';
        }
        if ( preg_match( '/^([a-z][a-z0-9+.-]*):/i', $url, $match ) ) {
            if ( ! in_array( strtolower( $match[1] ), [ 'http', 'https', 'mailto', 'tel' ], true ) ) {
                return '';
            }
        } elseif ( ! in_array( $url[0], [ '/', '#', '?' ], true ) ) {
            $url = 'http://' . $url;
        }
        $url = (string) preg_replace( '/&(?!#?[a-z0-9]+;)/i', '&amp;', $url );
        return str_replace( [ '&amp;', "'" ], [ '&#038;', '&#039;' ], $url );
    }
}

if ( ! function_exists( 'wp_kses_post' ) ) {
    // Only drops script elements and on* attributes; the real allowlist runs on the site (check-orders).
    function wp_kses_post( $text ) {
        $text = (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $text );
        return (string) preg_replace( '#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $text );
    }
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $text ) {
        return trim( strip_tags( (string) $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- this is the wp_strip_all_tags() stand-in.
    }
}

if ( ! function_exists( 'wpautop' ) ) {
    // Like WordPress for simple input: blank lines split paragraphs, single line breaks become
    // `<br />`, blocks that already are HTML paragraphs or tables are left alone.
    function wpautop( $text ) {
        $text = trim( str_replace( "\r\n", "\n", (string) $text ) );
        if ( '' === $text ) {
            return '';
        }
        $out = [];
        foreach ( preg_split( '/\n\s*\n/', $text ) as $chunk ) {
            $chunk = trim( $chunk );
            $out[] = preg_match( '/^<(?:p|table|div|ul|ol|h[1-6]|blockquote)\b/i', $chunk )
                ? $chunk
                : '<p>' . str_replace( "\n", "<br />\n", $chunk ) . '</p>';
        }
        return implode( "\n", $out ) . "\n";
    }
}

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) {
        return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- this is the wp_json_encode() stand-in.
    }
}

if ( ! function_exists( 'wp_check_invalid_utf8' ) ) {
    function wp_check_invalid_utf8( $text ) {
        return preg_match( '//u', (string) $text ) ? (string) $text : '';
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $text ) {
        $text = strip_tags( wp_check_invalid_utf8( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- this is a WordPress stand-in.
        return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $text ) );
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( $text ) {
        return trim( strip_tags( wp_check_invalid_utf8( $text ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- this is a WordPress stand-in.
    }
}

if ( ! function_exists( 'is_email' ) ) {
    function is_email( $email ) {
        return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
    }
}

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Squiz.Classes.ClassFileName.NoMatch -- one file of stand-ins with WordPress's names.

if ( ! class_exists( 'WP_Error' ) ) {
    /** An error with a code, message and data. */
    class WP_Error {

        /** @var string|int */
        private $code;

        /** @var string */
        private $message;

        /** @var mixed */
        private $data;

        /**
         * @param string|int $code
         * @param string     $message
         * @param mixed      $data
         */
        public function __construct( $code = '', $message = '', $data = '' ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code() {
            return $this->code;
        }

        public function get_error_message() {
            return $this->message;
        }

        public function get_error_data() {
            return $this->data;
        }
    }
}//end if

if ( ! function_exists( 'wp_kses' ) ) {
    // Pass-through: unit tests check logos against the allowlist; the real wp_kses() runs on the site.
    function wp_kses( $content, $allowed_html ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as WordPress.
        return $content;
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}

if ( ! function_exists( '_n' ) ) {
    function _n( $single, $plural, $number, $domain = 'default' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as WordPress.
        return 1 === (int) $number ? $single : $plural;
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ) {
        return abs( (int) $maybeint );
    }
}

if ( ! function_exists( 'current_time' ) ) {
    // GMT only: the plugin always asks for GMT.
    function current_time( $type, $gmt = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as WordPress.
        return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
    }
}

if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) {
        return 'https://shop.test' . $path;
    }
}

if ( ! function_exists( 'add_query_arg' ) ) {
    // Array form only, on a URL without a query string.
    function add_query_arg( $args, $url ) {
        return $url . '?' . http_build_query( $args );
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this is the wp_parse_url() stand-in.
    }
}

if ( ! function_exists( 'sanitize_hex_color' ) ) {
    function sanitize_hex_color( $color ) {
        return preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', (string) $color ) ? $color : null;
    }
}

if ( ! function_exists( 'date_i18n' ) ) {
    // English month names only.
    function date_i18n( $format, $timestamp = false ) {
        return gmdate( (string) $format, false === $timestamp ? time() : (int) $timestamp );
    }
}

if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url ) {
        return str_replace( [ '&#038;', '&#039;' ], [ '&', "'" ], esc_url( $url ) );
    }
}

if ( ! function_exists( 'wp_date' ) ) {
    // UTC only.
    function wp_date( $format, $timestamp = null ) {
        return gmdate( (string) $format, null === $timestamp ? time() : (int) $timestamp );
    }
}

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) {
        return 'https://shop.test/wp-admin/' . ltrim( (string) $path, '/' );
    }
}
