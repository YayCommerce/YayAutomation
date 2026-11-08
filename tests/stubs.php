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
    function apply_filters( $hook_name, $value, ...$args ) {
        foreach ( $GLOBALS['yaymatik_test_filters'][ $hook_name ] ?? [] as $callback ) {
            $value = $callback( $value, ...$args );
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

if ( ! function_exists( 'get_option' ) ) {
    // Options a test set with update_option(); `admin_email` is always set.
    function get_option( $name, $default_value = false ) {
        $options = $GLOBALS['yaymatik_test_options'] ?? [];
        if ( array_key_exists( $name, $options ) ) {
            return $options[ $name ];
        }
        return 'admin_email' === $name ? 'admin@shop.test' : $default_value;
    }
}

if ( ! function_exists( 'wp_timezone' ) ) {
    function wp_timezone() {
        return new DateTimeZone( get_option( 'timezone_string', 'UTC' ) );
    }
}

if ( ! function_exists( 'wc_get_is_paid_statuses' ) ) {
    function wc_get_is_paid_statuses() {
        return [ 'processing', 'completed' ];
    }
}

if ( ! function_exists( 'get_userdata' ) ) {
    // Users a test set in `$GLOBALS['yaymatik_test_users']`, id => object with `roles`, `user_registered`, `meta`.
    function get_userdata( $user_id ) {
        return $GLOBALS['yaymatik_test_users'][ $user_id ] ?? false;
    }
}

if ( ! function_exists( 'get_user_meta' ) ) {
    function get_user_meta( $user_id, $key = '', $single = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as WordPress.
        return $GLOBALS['yaymatik_test_users'][ $user_id ]->meta[ $key ] ?? '';
    }
}

if ( ! function_exists( 'wc_get_product_cat_ids' ) ) {
    // Categories a test set in `$GLOBALS['yaymatik_test_categories']`, product id => term ids.
    function wc_get_product_cat_ids( $product_id ) {
        return $GLOBALS['yaymatik_test_categories'][ $product_id ] ?? [];
    }
}

if ( ! function_exists( 'get_term_field' ) ) {
    function get_term_field( $field, $term, $taxonomy = '', $context = 'display' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as WordPress.
        return 'Category ' . $term;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $option, $value, $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as WordPress.
        $GLOBALS['yaymatik_test_options'][ $option ] = $value;
        return true;
    }
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! function_exists( 'get_transient' ) ) {
    // No expiry: tests move a clock, and code compares its own window end.
    function get_transient( $transient ) {
        return $GLOBALS['yaymatik_test_transients'][ $transient ] ?? false;
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $transient, $value, $expiration = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as WordPress.
        $GLOBALS['yaymatik_test_transients'][ $transient ] = $value;
        return true;
    }
}

if ( ! function_exists( 'add_action' ) ) {
    // Same store as `add_filter()`; priority and argument count don't matter to the tests.
    function add_action( $hook_name, $callback ) {
        return add_filter( $hook_name, $callback );
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( $hook_name, ...$args ) {
        foreach ( $GLOBALS['yaymatik_test_filters'][ $hook_name ] ?? [] as $callback ) {
            $callback( ...$args );
        }
    }
}

if ( ! function_exists( 'get_comment' ) ) {
    // Comments a test set in `$GLOBALS['yaymatik_test_comment_rows']`, id => object with WP_Comment's fields and `meta`.
    function get_comment( $comment_id ) {
        return $GLOBALS['yaymatik_test_comment_rows'][ $comment_id ] ?? null;
    }
}

if ( ! function_exists( 'get_comment_meta' ) ) {
    function get_comment_meta( $comment_id, $key = '', $single = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as WordPress.
        return $GLOBALS['yaymatik_test_comment_rows'][ $comment_id ]->meta[ $key ] ?? '';
    }
}

if ( ! function_exists( 'wc_strtolower' ) ) {
    function wc_strtolower( $text ) {
        return mb_strtolower( $text );
    }
}

if ( ! function_exists( 'wp_get_comment_status' ) ) {
    function wp_get_comment_status( $comment ) {
        return '1' === (string) $comment->comment_approved ? 'approved' : 'unapproved';
    }
}

if ( ! function_exists( 'wc_get_product' ) ) {
    // Products a test set in `$GLOBALS['yaymatik_test_products']`, by id.
    function wc_get_product( $product_id ) {
        return $GLOBALS['yaymatik_test_products'][ $product_id ] ?? false;
    }
}

if ( ! function_exists( 'wp_slash' ) ) {
    function wp_slash( $value ) {
        return is_string( $value ) ? addslashes( $value ) : $value;
    }
}

if ( ! function_exists( 'metadata_exists' ) ) {
    function metadata_exists( $meta_type, $object_id, $meta_key ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- same signature as WordPress.
        return isset( $GLOBALS['yaymatik_test_users'][ $object_id ]->meta ) && array_key_exists( $meta_key, $GLOBALS['yaymatik_test_users'][ $object_id ]->meta );
    }
}

if ( ! function_exists( 'update_user_meta' ) ) {
    // Unslashes its key and value, as WordPress does.
    function update_user_meta( $user_id, $meta_key, $meta_value ) {
        if ( ! empty( $GLOBALS['yaymatik_test_meta_write_fails'] ) ) {
            return false;
        }
        $GLOBALS['yaymatik_test_users'][ $user_id ]->meta[ stripslashes( $meta_key ) ] = stripslashes( $meta_value );
        return true;
    }
}
