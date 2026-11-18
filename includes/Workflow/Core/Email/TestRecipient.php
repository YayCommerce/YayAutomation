<?php
namespace YayMatik\Workflow\Core\Email;

defined( 'ABSPATH' ) || exit;

/** Where a user's test emails go: the address they saved, else their profile email. */
final class TestRecipient {

    public const META = 'yaymatik_test_email';

    /**
     * @param int $user_id
     */
    public static function of( int $user_id ): string {
        $saved = (string) get_user_meta( $user_id, self::META, true );
        if ( is_email( $saved ) ) {
            return $saved;
        }
        $user = get_userdata( $user_id );
        return $user ? (string) $user->user_email : '';
    }

    /**
     * @param int   $user_id
     * @param mixed $email
     * @return string|\WP_Error The saved address.
     */
    public static function save( int $user_id, $email ) {
        $email = self::valid( $email );
        if ( is_wp_error( $email ) ) {
            return $email;
        }
        update_user_meta( $user_id, self::META, $email );
        return $email;
    }

    /**
     * A send-test's address: `$recipient` when given, else `of()`.
     *
     * @param int   $user_id
     * @param mixed $recipient
     * @return string|\WP_Error
     */
    public static function for_send( int $user_id, $recipient ) {
        return null === $recipient ? self::of( $user_id ) : self::valid( $recipient );
    }

    /**
     * @param mixed $email
     * @return string|\WP_Error
     */
    private static function valid( $email ) {
        $email = is_string( $email ) ? sanitize_email( $email ) : '';
        if ( ! is_email( $email ) ) {
            return new \WP_Error( 'yaymatik_invalid_email', __( 'Enter a valid email address.', 'yaymatik' ), [ 'status' => 400 ] );
        }
        return $email;
    }
}
