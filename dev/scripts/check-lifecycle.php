<?php
/**
 * Review data, site blocks, and account-created and high-value runs through the real Dispatcher and Worker, on real orders, products,
 * comments, users and workflows, all temporary, at a random `.test` domain. Mail never leaves.
 * Prints PASS / FAIL lines; removes its rows. Run through check-lifecycle.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

use YayMatik\Engine\Privacy\PersonalData;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Email\EmailPreview;
use YayMatik\Workflow\Core\Email\ProductGrid;
use YayMatik\Workflow\Core\Email\ReviewProducts;
use YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Core\Runtime\RunPresenter;
use YayMatik\Workflow\Core\Runtime\Runtime;
use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\Schema;
use YayMatik\Workflow\Core\Storage\UnsubscribeStore;
use YayMatik\Workflow\Core\Storage\WorkflowStore;

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
$flows    = [];
$runs     = [];

global $wpdb;

$fails = 0;
$ok    = function ( bool $pass, string $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $fails += $pass ? 0 : 1;
};

$cleanup = function () use ( $wpdb, $domain, &$posts, &$orders, &$comments, &$users, &$flows, &$runs ) {
    $runs_table = RunStore::table();
    foreach ( $flows as $flow_id ) {
        WorkflowStore::deactivate( $flow_id );
        WorkflowStore::delete( $flow_id );
        $runs = array_merge( $runs, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$runs_table} WHERE workflow_id = %d", $flow_id ) ) ) );
    }
    $runs = array_unique( $runs );
    foreach ( $runs as $run_id ) {
        as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $run_id ], ActionSchedulerQueue::GROUP );
    }
    if ( $runs ) {
        RunStore::delete_with_steps( $runs );
    }
    $unsubscribes = Schema::table( Schema::UNSUBSCRIBES );
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$unsubscribes} WHERE email LIKE %s", '%@' . $wpdb->esc_like( $domain ) . '%' ) );
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

$replied       = $product( 'Check reply' );
$saved         = $order( [ $replied ], "reply@{$domain}" );
$parent_review = $review( $replied->get_id(), [ 'comment_author_email' => "someone@{$domain}" ] );
$review(
    $replied->get_id(),
    [
        'comment_author_email' => "reply@{$domain}",
        'comment_parent'       => $parent_review,
    ]
);
$ok( [ $replied->get_id() ] === $ids( $saved ), "a reply to someone's review doesn't count" );

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
$ok( 6 === count( $ids( $saved ) ) && 6 === substr_count( $shown( $saved, true ), '#reviews' ), 'at most 6 products' );

$returned = $product( 'Check returned' );
$stays    = $product( 'Check stays' );
$saved    = $order( [ $returned, $stays ], "refund@{$domain}" );
$line_id  = 0;
foreach ( $saved->get_items() as $line ) {
    $line_id = $returned->get_id() === $line->get_product_id() ? $line->get_id() : $line_id;
}
$refund = wc_create_refund(
    [
        'order_id'   => $saved->get_id(),
        'amount'     => 0,
        'line_items' => [
            $line_id => [
                'qty'          => 1,
                'refund_total' => 0,
            ],
        ],
    ]
);
$ok( ! is_wp_error( $refund ) && [ $stays->get_id() ] === $ids( $saved ), 'a fully refunded line is left out' );

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

// Account created: real workflows through the real Dispatcher; events start at flush().
$site_flows = WorkflowStore::active_for_trigger( 'customer_account_created' );
if ( $site_flows ) {
    WP_CLI::error( "Deactivate workflow {$site_flows[0]['id']} first: it would start on this script's accounts." );
}
wp_set_current_user(
    (int) get_users(
        [
            'role'   => 'administrator',
            'number' => 1,
            'fields' => 'ID',
        ]
    )[0]
);
add_filter( 'pre_wp_mail', fn() => true );
$runs_table = RunStore::table();
$new_flow   = function ( string $name, array $graph ) use ( &$flows ) {
    $request = new WP_REST_Request( 'POST', '/yaymatik/v1/workflows' );
    $request->set_header( 'content-type', 'application/json' );
    $request->set_body(
        wp_json_encode(
            [
                'name'  => "Check lifecycle: {$name} (temporary)",
                'graph' => $graph,
            ]
        )
    );
    $id      = (int) rest_do_request( $request )->get_data()['id'];
    $flows[] = $id;
    return 200 === rest_do_request( new WP_REST_Request( 'POST', "/yaymatik/v1/workflows/{$id}/activate" ) )->get_status() ? $id : 0;
};
$node       = fn( string $id, string $kind, int $y, string $block, array $config ) => [
    'id'       => $id,
    'kind'     => $kind,
    'position' => [
        'x' => 0,
        'y' => $y,
    ],
    'data'     => [
        'block'  => $block,
        'config' => (object) $config,
    ],
];
$email_node = fn( string $id, int $y ) => $node(
    $id,
    'action',
    $y,
    'send_email',
    [
        'to'      => '{{ customer.email }}',
        'subject' => 'Check lifecycle',
        'heading' => 'Hi',
        'message' => '<p>Hi {{ customer.first_name | default: "there" }},</p>',
    ]
);
$runs_of    = function ( string $subject ) use ( $wpdb, $runs_table, &$runs ) {
    $found = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$runs_table} WHERE subject = %s", $subject ) ) );
    $runs  = array_merge( $runs, $found );
    return $found;
};
// A run's job is taken over by the script, as check-carts does.
$slice          = function ( int $run_id ) {
    as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $run_id ], ActionSchedulerQueue::GROUP );
    return Runtime::worker()->run( $run_id );
};
$signup         = function ( string $name, string $first_name = '' ) use ( $domain, &$users ) {
    $id = wc_create_new_customer(
        "{$name}@{$domain}",
        'yaymatik_check_' . $name . wp_rand( 1000, 9999 ),
        wp_generate_password(),
        [ 'first_name' => $first_name ]
    );
    if ( is_wp_error( $id ) ) {
        throw new RuntimeException( $id->get_error_message() );
    }
    $users[] = $id;
    return $id;
};
$saved_customer = function ( string $name ) use ( $domain, &$users ) {
    $customer = new WC_Customer();
    $customer->set_email( "{$name}@{$domain}" );
    $customer->set_username( 'yaymatik_check_' . $name . wp_rand( 1000, 9999 ) );
    $customer->set_password( wp_generate_password() );
    $id      = $customer->save();
    $users[] = $id;
    return $id;
};
$customer_field = function ( int $id, string $key ) {
    return DataRegistry::entities()['customer']->fields()[ $key ]['value']( RefResolver::account( $id ) );
};

$before = $signup( 'no-flow' );
Runtime::dispatcher()->flush();
$ok( [] === $runs_of( "user:{$before}" ), 'account created, no workflow: no run' );

$account_flow = $new_flow(
    'account',
    [
        'nodes' => [ $node( 'trigger', 'trigger', 0, 'customer_account_created', [] ), $email_node( 'mail', 170 ) ],
        'edges' => [
            [
                'id'     => 'e1',
                'source' => 'trigger',
                'target' => 'mail',
            ],
        ],
    ]
);
$ok( $account_flow > 0, 'a workflow on the trigger activates' );

$shopper = $signup( 'shopper', 'Ann' );
Runtime::dispatcher()->flush();
$found = $runs_of( "user:{$shopper}" );
$ok( 1 === count( $found ) && 'queued' === RunStore::find( $found[0] )['status'], 'wc_create_new_customer: one queued run about user:<id>' );

$from_code = $saved_customer( 'from-code' );
Runtime::dispatcher()->flush();
$ok( [] === $runs_of( "user:{$from_code}" ), 'new WC_Customer … save(): no run' );

$both_shopper = $signup( 'both-shopper' );
$both_saved   = $saved_customer( 'both-saved' );
Runtime::dispatcher()->flush();
$ok( 1 === count( $runs_of( "user:{$both_shopper}" ) ) && [] === $runs_of( "user:{$both_saved}" ), 'one request, a shopper and a saved customer: one run, the shopper\'s' );

$added   = wp_insert_user(
    [
        'user_login' => 'yaymatik_check_added' . wp_rand( 1000, 9999 ),
        'user_email' => "added@{$domain}",
        'user_pass'  => wp_generate_password(),
        'role'       => 'customer',
    ]
);
$users[] = $added;
Runtime::dispatcher()->flush();
$ok( [] === $runs_of( "user:{$added}" ), 'wp_insert_user: no run' );

// Billing details filled in memory.
$ok( "shopper@{$domain}" === $customer_field( $shopper, 'email' ) && 'Ann' === $customer_field( $shopper, 'first_name' ), 'empty billing: email and first name come from the account' );
$ok( '' === get_user_meta( $shopper, 'billing_email', true ) && '' === get_user_meta( $shopper, 'billing_first_name', true ), 'reading never saves billing details' );
UnsubscribeStore::add( "shopper@{$domain}" );
$ok( true === $customer_field( $shopper, 'unsubscribed' ), 'unsubscribed reads the account email' );
$wpdb->delete( Schema::table( Schema::UNSUBSCRIBES ), [ 'email' => "shopper@{$domain}" ] );
$ok( 0.0 === $customer_field( $shopper, 'paid_order_count' ) && false === $customer_field( $shopper, 'is_first_order' ), 'no paid order: count 0, not a first order' );
$guest_order = $order( [ $mug ], "shopper@{$domain}" );
$guest_order->set_status( 'processing' );
$guest_order->save();
$ok( 1.0 === $customer_field( $shopper, 'paid_order_count' ) && true === $customer_field( $shopper, 'is_first_order' ), 'a guest order with that email counts; one paid order is the first' );

$billed = $signup( 'billed', 'Account' );
update_user_meta( $billed, 'billing_email', "billed-other@{$domain}" );
update_user_meta( $billed, 'billing_first_name', 'Billing' );
$ok( "billed-other@{$domain}" === $customer_field( $billed, 'email' ) && 'Billing' === $customer_field( $billed, 'first_name' ), 'existing billing values win' );

// How a run reads in the admin.
$subject_of = fn( string $subject ) => RunPresenter::item(
    [
        'id'            => 1,
        'workflow_id'   => 1,
        'version_id'    => 1,
        'trigger_id'    => 'customer_account_created',
        'subject'       => $subject,
        'status'        => 'completed',
        'error'         => null,
        'wait_until'    => null,
        'parent_run_id' => 0,
        'root_run_id'   => 1,
        'depth'         => 0,
        'started_at'    => null,
        'finished_at'   => null,
    ],
    0
);
$shown_run  = $subject_of( "user:{$shopper}" );
$ok( "shopper@{$domain}" === $shown_run['subjectLabel'] && admin_url( "user-edit.php?user_id={$shopper}" ) === $shown_run['subjectUrl'], 'run subject, empty billing: account email, user link' );
$ok( "billed-other@{$domain}" === $subject_of( "user:{$billed}" )['subjectLabel'], 'run subject, changed billing email: the billing email, the recipient' );

$deleted = $signup( 'deleted' );
Runtime::dispatcher()->flush();
$queued = $runs_of( "user:{$deleted}" )[0] ?? 0;
wp_delete_user( $deleted );
$users     = array_diff( $users, [ $deleted ] );
$shown_run = $subject_of( "user:{$deleted}" );
$ok( "Customer #{$deleted}" === $shown_run['subjectLabel'] && null === $shown_run['subjectUrl'], 'run subject, deleted user: Customer #id, no link' );
$ok( 'skipped' === $slice( $queued ) && 'The customer was deleted.' === RunStore::find( $queued )['error'], 'queued run of a deleted user: Skipped, The customer was deleted.' );

WorkflowStore::deactivate( $account_flow );
$wait_flow = $new_flow(
    'account wait',
    [
        'nodes' => [
            $node( 'trigger', 'trigger', 0, 'customer_account_created', [] ),
            $node(
                'wait',
                'action',
                170,
                'wait',
                [
                    'delay' => [
                        'value' => 1,
                        'unit'  => 'days',
                    ],
                ]
            ),
            $email_node( 'mail', 340 ),
        ],
        'edges' => [
            [
                'id'     => 'e1',
                'source' => 'trigger',
                'target' => 'wait',
            ],
            [
                'id'     => 'e2',
                'source' => 'wait',
                'target' => 'mail',
            ],
        ],
    ]
);

/** A run of the wait workflow, left waiting by its first slice. */
$waiting_run = function ( int $user_id ) use ( $runs_of, $slice ) {
    Runtime::dispatcher()->flush();
    $run_id = $runs_of( "user:{$user_id}" )[0] ?? 0;
    $slice( $run_id );
    return $run_id;
};
$leaves      = $signup( 'leaves' );
$waiting     = $waiting_run( $leaves );
$ok( 'waiting' === RunStore::find( $waiting )['status'], 'a run waits before its email' );
wp_delete_user( $leaves );
$users = array_diff( $users, [ $leaves ] );
RunStore::save( $waiting, [ 'wait_until' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
$status = $slice( $waiting );
$mail   = array_values( array_filter( RunStepStore::for_run( $waiting ), fn( array $step ) => 'mail' === $step['step_id'] ) )[0] ?? [];
$ok( 'completed' === $status && 'skipped' === ( $mail['status'] ?? '' ) && 'The customer was deleted.' === ( $mail['error'] ?? '' ), 'waiting run of a deleted user: its email is skipped, The customer was deleted.' );

// Personal data.
$privacy = PersonalData::get_instance();
$by_acct = $signup( 'erase-account' );
$run_a   = $waiting_run( $by_acct );
$items   = $privacy->export( "erase-account@{$domain}" )['data'];
$ok( in_array( "yaymatik-run-{$run_a}", array_column( $items, 'item_id' ), true ), 'export lists a user: run by the account email' );
$privacy->erase( "erase-account@{$domain}" );
$ok( 'cancelled' === RunStore::find( $run_a )['status'], 'erase by the account email cancels its waiting run' );

$by_bill = $signup( 'erase-bill' );
update_user_meta( $by_bill, 'billing_email', "erase-billing@{$domain}" );
$run_b = $waiting_run( $by_bill );
$privacy->erase( "erase-billing@{$domain}" );
$ok( 'cancelled' === RunStore::find( $run_b )['status'], 'erase by the billing email cancels its waiting run' );

$bystander = $signup( 'bystander' );
$run_c     = $waiting_run( $bystander );
$privacy->erase( "no-runs@{$domain}" );
$ok( 'waiting' === RunStore::find( $run_c )['status'], 'erasing someone else leaves a run alone' );

// Site blocks.
$site       = DataRegistry::entities()['site'];
$site_field = fn( string $key ) => $site->fields()[ $key ];
$block      = fn( string $key, bool $html ) => DataItem::display( $site_field( $key ), null, $html );
$account    = wc_get_page_permalink( 'myaccount' );
$shop_page  = wc_get_page_permalink( 'shop' );
$ok( $account === $site_field( 'account_url' )['value']( null ) && $shop_page === $site_field( 'shop_url' )['value']( null ), "site URLs are WooCommerce's pages" );
$links = $block( 'welcome_links', true );
$base  = sanitize_hex_color( (string) get_option( 'woocommerce_email_base_color', '' ) );
$base  = $base ? $base : '#7f54b3';
$ok( false !== strpos( $links, esc_url( $account ) ) && false !== strpos( $links, esc_url( $shop_page ) ) && false === strpos( $links, "\n" ) && false !== strpos( $links, $base ) && false !== strpos( $links, 'border:1px solid ' . $base ), 'welcome_links: both pages, one line, solid and outline in the base color' );
$ok( "Go to your account: {$account}\nVisit the shop: {$shop_page}" === $block( 'welcome_links', false ), 'welcome_links in text: one line each' );
add_filter( 'pre_option_woocommerce_email_base_color', fn() => '#f7f7f7' );
$ok( false !== strpos( $block( 'welcome_links', true ), 'border:1px solid #202020' ), 'a light store color: the outline button stays readable' );
remove_all_filters( 'pre_option_woocommerce_email_base_color' );
$ok( false !== strpos( $block( 'shop_button', true ), esc_url( $shop_page ) ) && "Visit the shop: {$shop_page}" === $block( 'shop_button', false ), 'shop_button: only the shop' );
$ok( false !== strpos( $block( 'account_button', true ), esc_url( $account ) ) && "Go to your account: {$account}" === $block( 'account_button', false ), 'account_button: only the account' );

$newest = ProductGrid::newest();
$shows  = $block( 'new_products', true );
$ok( count( $newest ) <= ProductGrid::MAX && ! array_filter( $newest, fn( WC_Product $item ) => 'publish' !== $item->get_status() || ! $item->is_visible() ) && ( ! $newest || false !== strpos( $shows, esc_url( $newest[0]->get_permalink() ) ) ), 'new_products: up to 4 newest, published, visible' );
$newest_prop = new ReflectionProperty( ProductGrid::class, 'newest' );
$newest_prop->setAccessible( true );
$kept_newest = $newest_prop->getValue();
$newest_prop->setValue( null, [] );
$ok( '' === $block( 'new_products', true ), 'new_products, none: empty' );
$newest_prop->setValue( null, $kept_newest );

$welcome_graph = [
    'nodes' => [ $node( 'trigger', 'trigger', 0, 'customer_account_created', [] ), $email_node( 'mail', 170 ) ],
    'edges' => [
        [
            'id'     => 'e1',
            'source' => 'trigger',
            'target' => 'mail',
        ],
    ],
];
$preview       = EmailPreview::render(
    json_decode( wp_json_encode( $welcome_graph ), true ),
    'mail',
    [
        'to'      => '{{ customer.email }}',
        'subject' => 'Welcome to {{ site.name }}',
        'heading' => 'Welcome',
        'message' => '<p>Hi {{ customer.first_name }},</p><p>{{ site.welcome_links }}</p>',
    ]
);
$ok( ! is_wp_error( $preview ) && true === $preview['sample'] && false !== strpos( $preview['html'], 'Hi Jane,' ) && false !== strpos( $preview['subject'], wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) && false !== strpos( $preview['html'], esc_url( $account ) ), 'preview of a customer email: sample data, Jane, the real site name and links' );

// High-value alert: the template's trigger and condition on real orders.
$alert_flow = $new_flow(
    'high value',
    [
        'nodes' => [
            $node(
                'trigger',
                'trigger',
                0,
                'order_status_changed',
                [
                    'from'          => [ 'pending', 'on-hold', 'failed' ],
                    'to'            => [ 'processing', 'completed' ],
                    'only_checkout' => false,
                ]
            ),
            $node(
                'condition',
                'condition',
                170,
                'condition',
                [
                    'match' => 'all',
                    'rules' => [
                        [
                            'field'    => 'order.total',
                            'operator' => 'greater_or_equal',
                            'value'    => '500',
                        ],
                    ],
                ]
            ),
            $node( 'note', 'action', 340, 'add_order_note', [ 'note' => 'Check lifecycle: alert' ] ),
        ],
        'edges' => [
            [
                'id'     => 'e1',
                'source' => 'trigger',
                'target' => 'condition',
            ],
            [
                'id'           => 'e2',
                'source'       => 'condition',
                'target'       => 'note',
                'sourceHandle' => 'then',
            ],
        ],
    ]
);
$ok( $alert_flow > 0, 'the alert workflow activates' );

$alert_runs = fn( int $order_id ) => array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$runs_table} WHERE workflow_id = %d AND subject = %s ORDER BY id", $alert_flow, "order:{$order_id}" ) ) );
$at_status  = function ( string $status, float $total = 600.0 ) use ( &$orders ) {
    $saved = wc_create_order( [ 'status' => $status ] );
    $saved->set_total( $total );
    $saved->save();
    $orders[] = $saved->get_id();
    Runtime::dispatcher()->flush();
    return $saved;
};
$move       = function ( WC_Order $saved, string $to ) {
    $saved->set_status( $to );
    $saved->save();
    Runtime::dispatcher()->flush();
};
foreach ( [ 'pending', 'on-hold', 'failed' ] as $from ) {
    $saved = $at_status( $from );
    $move( $saved, 'processing' );
    $ok( 1 === count( $alert_runs( $saved->get_id() ) ), "{$from} → processing starts it" );
}
foreach ( [ 'pending', 'on-hold' ] as $from ) {
    $saved = $at_status( $from );
    $move( $saved, 'completed' );
    $ok( 1 === count( $alert_runs( $saved->get_id() ) ), "{$from} → completed starts it" );
}
// Created at this status, the order may already have started a run from pending.
$saved  = $at_status( 'completed' );
$before = count( $alert_runs( $saved->get_id() ) );
$move( $saved, 'processing' );
$ok( count( $alert_runs( $saved->get_id() ) ) === $before, "completed → processing doesn't" );

$saved  = $at_status( 'processing' );
$before = count( $alert_runs( $saved->get_id() ) );
$move( $saved, 'completed' );
$ok( count( $alert_runs( $saved->get_id() ) ) === $before, "processing → completed doesn't" );

$draft_order = new WC_Order();
$draft_order->set_status( 'auto-draft' );
$draft_order->set_total( 600 );
$draft_order->save();
$orders[] = $draft_order->get_id();
Runtime::dispatcher()->flush();
$move( $draft_order, 'processing' );
$ok( 1 === count( $alert_runs( $draft_order->get_id() ) ), 'a new order through auto-draft reads as pending' );

$condition_result = function ( float $total ) use ( $at_status, $move, $alert_runs, $slice ) {
    $saved = $at_status( 'pending', $total );
    $move( $saved, 'processing' );
    $run_id = $alert_runs( $saved->get_id() )[0] ?? 0;
    $slice( $run_id );
    return array_column( RunStepStore::for_run( $run_id ), 'status', 'step_id' );
};
$under            = $condition_result( 499.0 );
$ok( isset( $under['condition'] ) && ! isset( $under['note'] ), 'total 499 stops at the condition' );
$exact = $condition_result( 500.0 );
$ok( 'completed' === ( $exact['note'] ?? '' ), 'total 500 passes' );

$saved = $at_status( 'pending' );
$move( $saved, 'processing' );
$move( $saved, 'on-hold' );
$move( $saved, 'processing' );
$ok( 2 === count( $alert_runs( $saved->get_id() ) ), 'processing → on-hold → processing runs twice: the known limit' );
$saved = $at_status( 'pending' );
$move( $saved, 'processing' );
$move( $saved, 'on-hold' );
$move( $saved, 'completed' );
$ok( 2 === count( $alert_runs( $saved->get_id() ) ), 'processing → on-hold → completed runs twice: the known limit' );

// Preview of an email with a cross-sell grid: the newest paid order that has one, else the store's newest products.
$suggested = $product( 'Check Suggested' );
$with_grid = $product( 'Check With Cross-sells' );
$with_grid->set_cross_sell_ids( [ $suggested->get_id() ] );
$with_grid->save();
$no_grid  = $product( 'Check No Cross-sells' );
$has_grid = $order( [ $with_grid ], "grid@{$domain}" );
$has_grid->set_date_created( time() - MINUTE_IN_SECONDS );
$has_grid->set_status( 'completed' );
$has_grid->save();
$newer = $order( [ $no_grid ], "nogrid@{$domain}" );
$newer->set_status( 'completed' );
$newer->save();
$grid_mail  = [
    'to'      => '{{ customer.email }}',
    'subject' => 'Check grid',
    'heading' => 'Hi',
    'message' => '<p>These go well with what you bought:</p><p>{{ order.cross_sell_products }}</p>',
];
$grid_graph = json_decode(
    wp_json_encode(
        [
            'nodes' => [
                $node(
                    'trigger',
                    'trigger',
                    0,
                    'order_status_changed',
                    [
                        'from'          => [],
                        'to'            => [ 'completed' ],
                        'only_checkout' => false,
                    ]
                ),
                $node( 'mail', 'action', 170, 'send_email', $grid_mail ),
            ],
            'edges' => [
                [
                    'id'     => 'e1',
                    'source' => 'trigger',
                    'target' => 'mail',
                ],
            ],
        ]
    ),
    true
);
$picked     = EmailPreview::render( $grid_graph, 'mail', $grid_mail );
$ok( ! is_wp_error( $picked ) && (string) $has_grid->get_order_number() === $picked['order'] && [] === $picked['sampleProducts'] && false !== strpos( $picked['html'], 'Check Suggested' ), 'preview with a cross-sell grid: the newest paid order that has one, not the newer order without' );
$fallback = EmailPreview::render( $grid_graph, 'mail', $grid_mail, $newer->get_id() );
$newest   = array_values( array_filter( ProductGrid::newest(), fn( WC_Product $item ) => $item->get_id() !== $no_grid->get_id() ) );
$ok( ! is_wp_error( $fallback ) && (string) $newer->get_order_number() === $fallback['order'] && [ 'cross_sells' ] === $fallback['sampleProducts'] && ( ! $newest || false !== strpos( $fallback['html'], esc_url( $newest[0]->get_permalink() ) ) ), 'an order without cross-sells: its data, the grid from the newest products minus its own, sampleProducts set' );
$test = EmailPreview::send_test( $grid_graph, 'mail', $grid_mail, "preview@{$domain}", $newer->get_id() );
$ok( ! is_wp_error( $test ) && [ 'cross_sells' ] === $test['sampleProducts'], 'Send test makes the same choice' );

echo $fails ? "{$fails} failed\n" : "All passed\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
exit( $fails ? 1 : 0 );
