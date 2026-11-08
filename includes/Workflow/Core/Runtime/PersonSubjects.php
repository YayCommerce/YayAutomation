<?php
namespace YayMatik\Workflow\Core\Runtime;

use YayMatik\Workflow\Core\Storage\CartStore;

defined( 'ABSPATH' ) || exit;

/** The run subjects that are about one person, found by email: the personal data tools and Stop waiting runs read the same list. */
final class PersonSubjects {

    /**
     * Run subjects of the person's orders, carts, accounts and reviews: by billing email too, as a welcome run sends there.
     *
     * @param string $email Normalized.
     * @return string[]
     */
    public static function for_email( string $email ): array {
        $orders   = wc_get_orders(
            [
                'customer' => $email,
                'return'   => 'ids',
                'limit'    => -1,
                'type'     => 'shop_order',
            ]
        );
        $subjects = array_map( fn( $id ) => 'order:' . (int) $id, (array) $orders );
        foreach ( CartStore::for_email( $email ) as $cart ) {
            $subjects[] = 'cart:' . (int) $cart['id'];
        }
        $users = get_users(
            [
                'meta_key'   => 'billing_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_value' => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'fields'     => 'ID',
            ]
        );
        $user  = get_user_by( 'email', $email );
        if ( $user ) {
            $users[] = $user->ID;
        }
        foreach ( array_unique( array_map( 'intval', $users ) ) as $user_id ) {
            $subjects[] = 'user:' . $user_id;
        }
        // Reviews by the email typed, and by the accounts found above: a logged-in review stores the account's email at the time.
        $reviews = [ [ 'author_email' => $email ] ];
        foreach ( array_unique( array_map( 'intval', $users ) ) as $user_id ) {
            $reviews[] = [ 'user_id' => $user_id ];
        }
        foreach ( $reviews as $query ) {
            $ids = get_comments(
                $query + [
                    'type'   => 'review',
                    // 'all' leaves out spam and trash, which still hold the email.
                    'status' => [ 'approve', 'hold', 'spam', 'trash' ],
                    'fields' => 'ids',
                ]
            );
            foreach ( (array) $ids as $review_id ) {
                $subjects[] = 'review:' . (int) $review_id;
            }
        }
        return array_values( array_unique( $subjects ) );
    }
}
