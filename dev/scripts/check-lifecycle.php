<?php
/**
 * Review data on real orders, products and comments, all temporary, at a random `.test` domain.
 * Prints PASS / FAIL lines; removes its rows. Run through check-lifecycle.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Email\ProductGrid;
use YayMatik\Workflow\Core\Email\ReviewProducts;

if ( ! function_exists( 'WC' ) ) {
    WP_CLI::error( 'WooCommerce is not active.' );
}

// WooCommerce's own order emails stay off.
foreach ( WC()->mailer()->get_emails() as $wc_email ) {
    add_filter( 'woocommerce_email_enabled_' . $wc_email->id, '__return_false' );
}

$domain   = 'x' . wp_rand( 1000, 9999 ) . '.test';
$posts    = [];
$orders   = [];
$comments = [];
$users    = [];

$fails = 0;
$ok    = function ( bool $pass, string $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $fails += $pass ? 0 : 1;
};

$cleanup = function () use ( &$posts, &$orders, &$comments, &$users ) {
    foreach ( $comments as $comment_id ) {
        wp_delete_comment( $comment_id, true );
    }
    foreach ( $orders as $order_id ) {
        $saved_order = wc_get_order( $order_id );
        if ( $saved_order ) {
            $saved_order->delete( true );
        }
    }
    foreach ( $posts as $post_id ) {
        $saved_product = wc_get_product( $post_id );
        if ( $saved_product ) {
            $saved_product->delete( true );
        }
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ( $users as $user_id ) {
        wp_delete_user( $user_id );
    }
};
// Runs even when a check throws or PHP dies, so no test rows are left behind.
register_shutdown_function( $cleanup );

$product = function ( string $name, array $props = [] ) use ( &$posts ) {
    $item = new WC_Product_Simple();
    $item->set_name( $name );
    $item->set_status( $props['status'] ?? 'publish' );
    $item->set_regular_price( '10' );
    $item->set_reviews_allowed( $props['reviews'] ?? true );
    $posts[] = $item->save();
    return $item;
};
$order   = function ( array $items, string $email, int $user_id = 0 ) use ( &$orders ) {
    $saved = wc_create_order();
    foreach ( $items as $item ) {
        $saved->add_product( $item );
    }
    $saved->set_billing_email( $email );
    $saved->set_customer_id( $user_id );
    $saved->save();
    $orders[] = $saved->get_id();
    return $saved;
};
$review  = function ( int $product_id, array $fields ) use ( &$comments ) {
    $id         = wp_insert_comment(
        $fields + [
            'comment_post_ID'  => $product_id,
            'comment_type'     => 'review',
            'comment_approved' => 1,
            'comment_content'  => 'Check review',
        ]
    );
    $comments[] = $id;
    return $id;
};
$ids     = fn( WC_Order $saved ) => array_map( fn( WC_Product $item ) => $item->get_id(), ReviewProducts::for_order( wc_get_order( $saved->get_id() ) ) );

// Fresh order.
$mug   = $product( 'Check Mug' );
$fresh = $order( [ $mug ], "fresh@{$domain}" );
$item  = DataRegistry::entities()['order'];
$value = fn( string $key, WC_Order $saved ) => $item->fields()[ $key ]['value']( wc_get_order( $saved->get_id() ) );
$shown = fn( WC_Order $saved, bool $html ) => (string) $item->fields()['review_products']['display']( wc_get_order( $saved->get_id() ), $html );
$ok( true === $value( 'has_products_not_reviewed', $fresh ) && [ $mug->get_id() ] === $ids( $fresh ), 'a fresh order: true, its product listed' );
$html  = $shown( $fresh, true );
$ok( false !== strpos( $html, $mug->get_permalink() . '#reviews' ) && false === strpos( $html, "\n" ), 'block: links to #reviews, on one line' );
$ok( $mug->get_name() . ' — Leave a review: ' . $mug->get_permalink() . '#reviews' === $shown( $fresh, false ), 'block in text: Name — Leave a review: URL' );

// Who counts as having reviewed.
$by_email = $product( 'Check by email' );
$saved    = $order( [ $by_email ], "buyer@{$domain}" );
$review( $by_email->get_id(), [ 'comment_author_email' => "buyer@{$domain}" ] );
$ok( [] === $ids( $saved ) && false === $value( 'has_products_not_reviewed', $saved ), 'reviewed by billing email: false' );

$by_case = $product( 'Check by case' );
$saved   = $order( [ $by_case ], "buyer2@{$domain}" );
$review( $by_case->get_id(), [ 'comment_author_email' => strtoupper( "buyer2@{$domain}" ) ] );
$ok( [] === $ids( $saved ), 'the same email in other case counts' );

$account = wp_insert_user(
    [
        'user_login' => 'yaymatik_check_' . wp_rand( 1000, 9999 ),
        'user_email' => "account@{$domain}",
        'user_pass'  => wp_generate_password(),
    ]
);
$users[] = $account;
$by_user = $product( 'Check by user' );
$saved   = $order( [ $by_user ], "other-billing@{$domain}", $account );
$review(
    $by_user->get_id(),
    [
        'user_id'              => $account,
        'comment_author_email' => "yet-another@{$domain}",
    ]
);
$ok( [] === $ids( $saved ), 'reviewed by user id with another email: false' );

$held  = $product( 'Check held' );
$saved = $order( [ $held ], "held@{$domain}" );
$review(
    $held->get_id(),
    [
        'comment_author_email' => "held@{$domain}",
        'comment_approved'     => 0,
    ]
);
$ok( [] === $ids( $saved ), 'a held review counts' );

foreach ( [ 'spam', 'trash' ] as $state ) {
    $bad   = $product( 'Check ' . $state );
    $saved = $order( [ $bad ], "{$state}@{$domain}" );
    $review(
        $bad->get_id(),
        [
            'comment_author_email' => "{$state}@{$domain}",
            'comment_approved'     => $state,
        ]
    );
    $ok( [ $bad->get_id() ] === $ids( $saved ), "a {$state} review doesn't count" );
}

$plain = $product( 'Check plain comment' );
$saved = $order( [ $plain ], "plain@{$domain}" );
$review(
    $plain->get_id(),
    [
        'comment_author_email' => "plain@{$domain}",
        'comment_type'         => 'comment',
    ]
);
$ok( [ $plain->get_id() ] === $ids( $saved ), "a comment that isn't a review doesn't count" );

$others = $product( 'Check others' );
$saved  = $order( [ $others ], "me@{$domain}" );
$review( $others->get_id(), [ 'comment_author_email' => "someone-else@{$domain}" ] );
$ok( [ $others->get_id() ] === $ids( $saved ), "another customer's email doesn't count" );

$guest = $product( 'Check guest' );
$saved = $order( [ $guest ], "guest@{$domain}" );
$review(
    $guest->get_id(),
    [
        'user_id'              => 0,
        'comment_author_email' => "other-guest@{$domain}",
    ]
);
$ok( [ $guest->get_id() ] === $ids( $saved ), "a guest order isn't matched by other guests' reviews" );

// Which products can be listed.
$open   = $product( 'Check open' );
$closed = $product( 'Check closed', [ 'reviews' => false ] );
$draft  = $product( 'Check draft', [ 'status' => 'draft' ] );
$saved  = $order( [ $open, $closed, $draft ], "mixed@{$domain}" );
$ok( [ $open->get_id() ] === $ids( $saved ), 'closed to reviews and draft products are left out' );

$parent = new WC_Product_Variable();
$parent->set_name( 'Check variable' );
$parent->set_status( 'publish' );
$parent->set_reviews_allowed( true );
$posts[]    = $parent->save();
$variations = [];
foreach ( [ 'a', 'b' ] as $letter ) {
    $variation = new WC_Product_Variation();
    $variation->set_parent_id( $parent->get_id() );
    $variation->set_regular_price( '10' );
    $variation->set_sku( 'check-' . $letter . wp_rand( 1000, 9999 ) );
    $posts[]      = $variation->save();
    $variations[] = $variation;
}
$saved = $order( $variations, "variable@{$domain}" );
$ok( [ $parent->get_id() ] === $ids( $saved ) && 1 === substr_count( $shown( $saved, true ), '#reviews' ), 'two variations: one row, the parent' );

$gone  = $product( 'Check deleted' );
$kept  = $product( 'Check kept' );
$saved = $order( [ $gone, $kept ], "gone@{$domain}" );
$gone->delete( true );
$ok( [ $kept->get_id() ] === $ids( $saved ), 'a deleted product is skipped' );

$many  = array_map( fn( int $n ) => $product( "Check many {$n}" ), range( 1, 7 ) );
$saved = $order( $many, "many@{$domain}" );
$ok( ReviewProducts::MAX === count( $ids( $saved ) ) && 6 === substr_count( $shown( $saved, true ), '#reviews' ), 'at most 6 products' );

// Read when the step runs: no cache between reads.
$later = $product( 'Check later' );
$saved = $order( [ $later ], "later@{$domain}" );
$first = $ids( $saved );
$review( $later->get_id(), [ 'comment_author_email' => "later@{$domain}" ] );
$ok( [ $later->get_id() ] === $first && [] === $ids( $saved ), 'a review added after the first read counts on the next read' );

// Store-wide switch.
add_filter( 'pre_option_woocommerce_enable_reviews', fn() => 'no' );
$ok( false === $value( 'has_products_not_reviewed', $fresh ) && '' === $shown( $fresh, true ), 'reviews off: false and an empty block' );
remove_all_filters( 'pre_option_woocommerce_enable_reviews' );

// Preview with no order.
$sample = $item->sample();
$newest = ProductGrid::newest();
$ok( (bool) $newest === $sample['has_products_not_reviewed'] && ( ! $newest || false !== strpos( $sample['review_products']['html'], '#reviews' ) ), 'sample data renders from the newest products' );

echo $fails ? "{$fails} failed\n" : "All passed\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
exit( $fails ? 1 : 0 );
