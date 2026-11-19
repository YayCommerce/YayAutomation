<?php
/**
 * Session stand-ins for the login trigger tests: sessions a test sets per user, the current request's token, user meta adds.
 *
 * @package YayMatik
 */

// phpcs:disable Squiz.Classes.ClassFileName.NoMatch, Universal.Files.SeparateFunctionsFromOO.Mixed -- one file of stand-ins.
if ( ! class_exists( 'WP_Session_Tokens' ) ) {
    /** Sessions in `$GLOBALS['yaymatik_test_sessions'][ user id ][ token ]`. */
    class WP_Session_Tokens {

        /** @var int */
        private $user_id;

        private function __construct( int $user_id ) {
            $this->user_id = $user_id;
        }

        public static function get_instance( $user_id ) {
            return new self( (int) $user_id );
        }

        public function get( $token ) {
            return $GLOBALS['yaymatik_test_sessions'][ $this->user_id ][ $token ] ?? null;
        }

        public function update( $token, $session ) {
            $GLOBALS['yaymatik_test_sessions'][ $this->user_id ][ $token ] = $session;
        }
    }
}

if ( ! function_exists( 'wp_get_session_token' ) ) {
    function wp_get_session_token() {
        return $GLOBALS['yaymatik_test_session_token'] ?? '';
    }
}

if ( ! function_exists( 'add_user_meta' ) ) {
    // Unique only, as LastSeen adds it.
    function add_user_meta( $user_id, $meta_key, $meta_value, $unique = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as WordPress.
        $GLOBALS['yaymatik_test_user_meta_added'][] = [ (int) $user_id, $meta_key, $meta_value ];
        return 1;
    }
}
