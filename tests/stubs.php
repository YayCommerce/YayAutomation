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
    function esc_url( $url, $protocols = null ) {
        $url = str_replace( ' ', '%20', ltrim( (string) $url ) );
        $url = (string) preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\x80-\xff]|i', '', $url );
        if ( '' === $url ) {
            return '';
        }
        if ( preg_match( '/^([a-z][a-z0-9+.-]*):/i', $url, $match ) ) {
            if ( ! in_array( strtolower( $match[1] ), $protocols ?? [ 'http', 'https', 'mailto', 'tel' ], true ) ) {
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

if ( ! function_exists( 'wp_get_list_item_separator' ) ) {
    function wp_get_list_item_separator() {
        return ', ';
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
    function esc_url_raw( $url, $protocols = null ) {
        return str_replace( [ '&#038;', '&#039;' ], [ '&', "'" ], esc_url( $url, $protocols ) );
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

if ( ! function_exists( 'did_action' ) ) {
    // `init` has run, other hooks haven't, unless a test sets `$GLOBALS['yaymatik_test_did_action']`.
    function did_action( $hook_name ) {
        return $GLOBALS['yaymatik_test_did_action'][ $hook_name ] ?? ( 'init' === $hook_name ? 1 : 0 );
    }
}

if ( ! function_exists( 'plugins_url' ) ) {
    function plugins_url( $path = '', $plugin = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as WordPress.
        return 'https://shop.test/wp-content/plugins/' . ltrim( (string) $path, '/' );
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

if ( ! function_exists( 'get_users' ) ) {
    // Test users filtered by role, newest registered first (then the higher ID, when `orderby` names it), cut to the number asked for.
    function get_users( $args = [] ) {
        $users = array_values(
            array_filter(
                $GLOBALS['yaymatik_test_users'] ?? [],
                fn( $user ) => ! isset( $args['role'] ) || in_array( $args['role'], (array) ( $user->roles ?? [] ), true )
            )
        );
        $by_id = isset( $args['orderby']['ID'] );
        usort(
            $users,
            function ( $a, $b ) use ( $by_id ) {
                $newer = strcmp( (string) ( $b->user_registered ?? '' ), (string) ( $a->user_registered ?? '' ) );
                return 0 === $newer && $by_id ? ( $b->ID ?? 0 ) <=> ( $a->ID ?? 0 ) : $newer;
            }
        );
        return isset( $args['number'] ) ? array_slice( $users, 0, (int) $args['number'] ) : $users;
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

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
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

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- one file of stand-ins, like WP_Error above.
if ( ! class_exists( 'WP_Roles' ) ) {
    /** The roles a test sets: slug => [ 'name' => …, 'capabilities' => [ cap => bool ] ]. */
    class WP_Roles {

        /** @var array */
        public $roles;

        public function __construct( array $roles = [] ) {
            $this->roles = $roles;
        }

        public function get_names() {
            return array_map( fn( $role ) => $role['name'], $this->roles );
        }
    }
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound

if ( ! function_exists( 'wp_roles' ) ) {
    function wp_roles() {
        return $GLOBALS['yaymatik_test_roles'] ?? new WP_Roles();
    }
}

if ( ! function_exists( 'translate_user_role' ) ) {
    function translate_user_role( $name ) {
        return $name;
    }
}

if ( ! function_exists( 'is_super_admin' ) ) {
    function is_super_admin( $user_id = false ) {
        return ! empty( $GLOBALS['yaymatik_test_super_admins'][ $user_id ] );
    }
}

if ( ! function_exists( 'user_can' ) ) {
    function user_can( $user, $capability ) {
        return ! empty( $user->allcaps[ $capability ] );
    }
}

if ( ! function_exists( 'is_multisite' ) ) {
    // A network when a test sets `$GLOBALS['yaymatik_test_sites']` (site ids).
    function is_multisite() {
        return isset( $GLOBALS['yaymatik_test_sites'] );
    }
}

if ( ! function_exists( 'get_sites' ) ) {
    function get_sites( $args = [] ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as WordPress.
        return $GLOBALS['yaymatik_test_sites'] ?? [];
    }
}

if ( ! function_exists( 'switch_to_blog' ) ) {
    // Site 1 keeps the base prefix, as in WordPress.
    function switch_to_blog( $site_id ) {
        $GLOBALS['wpdb']->prefix = 1 === (int) $site_id ? $GLOBALS['wpdb']->base_prefix : $GLOBALS['wpdb']->base_prefix . $site_id . '_';
        return true;
    }
}

if ( ! function_exists( 'restore_current_blog' ) ) {
    function restore_current_blog() {
        $GLOBALS['wpdb']->prefix = $GLOBALS['wpdb']->base_prefix;
        return true;
    }
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
    function wp_cache_delete( $key, $group = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as WordPress.
        return true;
    }
}

if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = 'default' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as WordPress.
        return esc_html( $text );
    }
}

if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        return $GLOBALS['yaymatik_test_user_id'] ?? 0;
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    // Capabilities a test set in `$GLOBALS['yaymatik_test_caps']`.
    function current_user_can( $capability ) {
        return in_array( $capability, $GLOBALS['yaymatik_test_caps'] ?? [], true );
    }
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
    // A valid nonce is `nonce:<action>`.
    function wp_verify_nonce( $nonce, $action = -1 ) {
        return 'nonce:' . $action === $nonce ? 1 : false;
    }
}

if ( ! function_exists( 'sanitize_email' ) ) {
    function sanitize_email( $email ) {
        return (string) preg_replace( '/[^a-z0-9+_.@-]/i', '', trim( $email ) );
    }
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
    function rest_ensure_response( $response ) {
        return $response;
    }
}

if ( ! function_exists( 'register_rest_route' ) ) {
    // Routes land in `$GLOBALS['yaymatik_test_routes']`, route => args.
    function register_rest_route( $route_namespace, $route, $args = [] ) {
        $GLOBALS['yaymatik_test_routes'][ $route_namespace . $route ] = $args;
        return true;
    }
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- one file of stand-ins, like WP_Error above.
if ( ! class_exists( 'WP_REST_Server' ) ) {
    /** HTTP methods of a route. */
    class WP_REST_Server {
        const READABLE  = 'GET';
        const CREATABLE = 'POST';
        const EDITABLE  = 'POST, PUT, PATCH';
        const DELETABLE = 'DELETE';
    }
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    /** A request with a JSON body. */
    class WP_REST_Request {

        /** @var array */
        private $body;

        /**
         * @param array $body
         */
        public function __construct( array $body = [] ) {
            $this->body = $body;
        }

        public function get_json_params() {
            return $this->body;
        }

        /**
         * @param string $key
         */
        public function get_param( $key ) {
            return $this->body[ $key ] ?? null;
        }
    }
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
