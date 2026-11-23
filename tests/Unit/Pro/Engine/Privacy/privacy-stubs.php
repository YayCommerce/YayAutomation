<?php
/**
 * User lookups and meta deletes for the activity data tests, over `$GLOBALS['yaymatik_test_users']`.
 *
 * @package YayMatik
 */

if ( ! function_exists( 'get_user_by' ) ) {
    // By email only.
    function get_user_by( $field, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- same signature as WordPress.
        foreach ( $GLOBALS['yaymatik_test_users'] ?? [] as $user ) {
            if ( ( $user->user_email ?? '' ) === $value ) {
                return $user;
            }
        }
        return false;
    }
}

if ( ! function_exists( 'delete_user_meta' ) ) {
    function delete_user_meta( $user_id, $meta_key ) {
        if ( ! isset( $GLOBALS['yaymatik_test_users'][ $user_id ]->meta[ $meta_key ] ) ) {
            return false;
        }
        unset( $GLOBALS['yaymatik_test_users'][ $user_id ]->meta[ $meta_key ] );
        return true;
    }
}
