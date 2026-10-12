<?php
/**
 * Minimal stand-ins for the WordPress functions the unit-tested classes call. Each one behaves
 * like WordPress for the inputs the tests use; add a stub only when a test needs it.
 *
 * @package YayAutomation
 */

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as WordPress.
        return $text;
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
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
    // Like WordPress: tags stripped, line breaks and tabs removed, spaces collapsed, trimmed.
    function sanitize_text_field( $text ) {
        $text = strip_tags( wp_check_invalid_utf8( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- this is a WordPress stand-in.
        return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $text ) );
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    // Like WordPress: tags stripped, line breaks kept, trimmed.
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
         * @param string|int $code    Error code.
         * @param string     $message Error message.
         * @param mixed      $data    Error data.
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
