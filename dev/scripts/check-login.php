<?php
/**
 * Login triggers (wp-cli), one phase per call; check-login.sh drives real sign-ins over HTTP between the phases.
 *   claims                 claim cooldown on the real table, two claims at once (workflow ids no real workflow has)
 *   setup                  temporary users, orders and workflows; prints `STATE <json>`
 *   runs <state>           prints `RUNS <json>`: workflow key => subjects of its runs
 *   age <state> <user>     moves that user's claims and last seen 8 days back
 *   destroy <state> <user> ends every session of that user (a second factor step does, before the code)
 *   seen <state> <user> <s> last seen `s` seconds back, or `none`; the user's claims removed, so a cooldown hides nothing
 *   lastseen <state> <user> prints `SEEN <seconds back>`, or `SEEN none`
 *   seenrows <state> <user> <s> two last-seen rows `s` seconds back (claims removed), as two first visits at once leave; prints `ROWS <count>` with `count` instead of `s`
 *   added <state> <user> [t] prints `ADDED <last added meta>` (or none); with `t`, sets it to `t` first
 *   stock <state> <n>      the first product's stock
 *   privacy <state> <user> export and erase through WordPress's registered exporters and erasers
 *   template <state>       the Logged in, nothing added to cart template's graph (tests/Unit/Pro/fixtures), checked and activated; prints `FLOW <id>`
 *   finish <state> <user> <flow> [due]  ends the user's waits and runs due jobs (with `due`, only ends the waits);
 *                          prints `RESULT <status:steps of each run>` and `MAILS <count to the user>`
 *   saved <state> <user> <qty,qty> <ago>  a saved cart of the two products (0 leaves one out), last added `ago` seconds back
 *   twofactor <state> <user>  Two Factor's TOTP on for that user; prints `KEY <secret>`
 *   totp <key>             prints `CODE <the current TOTP code>`
 *   unclaim <user id>      removes that user's claims
 *   oidc                   OpenID Connect Generic set up for the local Keycloak (check-login.sh starts it)
 *   nocookie <state>       a sign-in whose cookies are never sent (`send_auth_cookies` false)
 *   checkout <state>       replays checkout's account creation for a guest with an older paid order; prints `COOKIE <name>=<value>`
 *   edition <lite|pro>     the dev license on or off
 *   cleanup <state> [ids]  removes everything setup made, and these orders
 * Prints PASS / FAIL lines. Run through check-login.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

use YayMatik\Pro\Workflow\Core\Cart\CartActivity;
use YayMatik\Pro\Workflow\Core\Session\LastSeen;
use YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\Schema;
use YayMatik\Workflow\Core\Storage\TriggerClaimStore;
use YayMatik\Workflow\Core\Storage\WorkflowStore;

global $wpdb;
$claims   = Schema::table( Schema::TRIGGER_CLAIMS );
$phase    = $args[0] ?? '';
$state    = isset( $args[1] ) ? json_decode( base64_decode( $args[1] ), true ) : []; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the state from setup, passed back by check-login.sh.
$password = 'Check-login-1!';

$fails = 0;
$ok    = function ( bool $pass, string $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $fails += $pass ? 0 : 1;
};

switch ( $phase ) {
    case 'claims':
        $ghost   = 999998;
        $subject = 'user:' . wp_rand( 100000, 999999 );
        $cleanup = function () use ( $wpdb, $claims, $ghost ) {
            $wpdb->delete( $claims, [ 'workflow_id' => $ghost ] );
        };
        register_shutdown_function( $cleanup );
        $cleanup();
        $age = function ( int $seconds ) use ( $wpdb, $claims, $ghost, $subject ) {
            $wpdb->update(
                $claims,
                [ 'claimed_at' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ],
                [
                    'workflow_id' => $ghost,
                    'subject'     => $subject,
                ]
            );
        };

        $ok( TriggerClaimStore::claim( $ghost, $subject, DAY_IN_SECONDS ), 'claim: the first claim wins' );
        $ok( ! TriggerClaimStore::claim( $ghost, $subject, DAY_IN_SECONDS ), 'claim: refused inside the cooldown' );
        $age( DAY_IN_SECONDS + 5 );
        $ok( ! TriggerClaimStore::claim( $ghost, $subject ), 'claim: without a cooldown, never again' );
        $ok( TriggerClaimStore::claim( $ghost, $subject, DAY_IN_SECONDS ), 'claim: taken again after the cooldown' );
        $ok( ! TriggerClaimStore::claim( $ghost, $subject, DAY_IN_SECONDS ), 'claim: the new claim starts a new cooldown' );
        TriggerClaimStore::release( $ghost, $subject );
        $ok( TriggerClaimStore::claim( $ghost, $subject, DAY_IN_SECONDS ), 'claim: released, then claimed again at once' );

        // Two at once: this process takes the old claim inside an open transaction, which holds the row lock; a second
        // wp process claims the same key meanwhile and must wait for the commit, then find nothing to take.
        $age( DAY_IN_SECONDS + 5 );
        $wpdb->query( 'START TRANSACTION' );
        $first = TriggerClaimStore::claim( $ghost, $subject, DAY_IN_SECONDS );
        $code  = sprintf( '$t = microtime( true ); $won = YayMatik\Workflow\Core\Storage\TriggerClaimStore::claim( %d, %s, DAY_IN_SECONDS ); echo (int) $won, " ", round( microtime( true ) - $t, 1 );', $ghost, var_export( $subject, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- a dev script on wp-env: the second wp process.
        $second = proc_open(
            [ 'wp', 'eval', $code ],
            [
                1 => [ 'pipe', 'w' ],
                2 => [ 'pipe', 'w' ],
            ],
            $pipes
        );
        // Long enough for the second process to boot WordPress and reach the lock.
        sleep( 6 );
        $wpdb->query( 'COMMIT' );
        list( $won, $waited ) = array_pad( explode( ' ', trim( (string) stream_get_contents( $pipes[1] ) ) ), 2, '' );
        $errors               = trim( (string) stream_get_contents( $pipes[2] ) );
        proc_close( $second );
        $ok( $first && '0' === $won, "claim: two at once, one wins (first: {$first}, second: '{$won}'" . ( $errors ? ", stderr: {$errors}" : '' ) . ')' );
        $ok( (float) $waited >= 1, "claim: the second waited {$waited} s for the first one's row lock" );
        break;

    case 'setup':
        if ( ! function_exists( 'WC' ) ) {
            WP_CLI::error( 'WooCommerce is not active.' );
        }
        $own = array_merge( WorkflowStore::active_for_trigger( 'user_logged_in' ), WorkflowStore::active_for_trigger( 'customer_logged_in' ) );
        if ( $own ) {
            WP_CLI::error( 'Deactivate this site\'s login workflows first: this check signs people in. Ids: ' . implode( ', ', array_column( $own, 'id' ) ) );
        }
        foreach ( WC()->mailer()->get_emails() as $wc_email ) {
            add_filter( 'woocommerce_email_enabled_' . $wc_email->id, '__return_false' );
        }
        $domain = 'login' . wp_rand( 1000, 9999 ) . '.test';
        $state  = [
            'domain'   => $domain,
            'options'  => [],
            'users'    => [],
            'orders'   => [],
            'flows'    => [],
            'password' => $password,
        ];
        // My Account sign-up with a password the form takes, for the automatic sign-in.
        foreach ( [
            'woocommerce_enable_myaccount_registration'  => 'yes',
            'woocommerce_registration_generate_password' => 'no',
            'woocommerce_registration_generate_username' => 'yes',
        ] as $option => $value ) {
            $state['options'][ $option ] = get_option( $option, null );
            update_option( $option, $value );
        }
        if ( ! get_role( 'check_login_buyer' ) ) {
            add_role( 'check_login_buyer', 'Check login buyer', [ 'read' => true ] );
        }
        if ( ! get_role( 'check_login_editor' ) ) {
            add_role( 'check_login_editor', 'Check login editor', [ 'read' => true, 'edit_posts' => true ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
        }
        // Key => role, statuses of its orders.
        $people = [
            'paid'       => [ 'customer', [ 'completed' ] ],
            'paid2'      => [ 'customer', [ 'processing' ] ],
            'paid3'      => [ 'customer', [ 'completed' ] ],
            'paid4'      => [ 'customer', [ 'completed' ] ],
            'paid5'      => [ 'customer', [ 'completed' ] ],
            'paid6'      => [ 'customer', [ 'completed' ] ],
            'noorder'    => [ 'customer', [] ],
            'unpaid'     => [ 'customer', [ 'failed', 'cancelled', 'refunded' ] ],
            'manager'    => [ 'shop_manager', [ 'completed' ] ],
            'buyer'      => [ 'check_login_buyer', [ 'completed' ] ],
            'editor'     => [ 'check_login_editor', [ 'completed' ] ],
            'subscriber' => [ 'subscriber', [] ],
            'ret'        => [ 'customer', [ 'completed' ] ],
            'ret2'       => [ 'customer', [ 'completed' ] ],
            'cart'       => [ 'customer', [ 'completed' ] ],
            't17'        => [ 'customer', [ 'completed' ] ],
            't18'        => [ 'customer', [ 'completed' ] ],
            'n24'        => [ 'customer', [ 'completed' ] ],
            'n25'        => [ 'customer', [ 'completed' ] ],
            'n26'        => [ 'customer', [ 'completed' ] ],
            'n27'        => [ 'customer', [ 'completed' ] ],
            'n28'        => [ 'customer', [ 'completed' ] ],
            'n29'        => [ 'customer', [ 'completed' ] ],
            'g1'         => [ 'customer', [ 'completed' ] ],
            'g2'         => [ 'customer', [ 'completed' ] ],
            'g3'         => [ 'customer', [ 'completed' ] ],
            'tfa'        => [ 'customer', [ 'completed' ] ],
            'switch'     => [ 'customer', [ 'completed' ] ],
            'dup'        => [ 'customer', [ 'completed' ] ],
        ];
        foreach ( $people as $key => list( $role, $statuses ) ) {
            $email = "{$key}@{$domain}";
            $id    = wp_insert_user(
                [
                    'user_login' => "{$key}-{$domain}",
                    'user_email' => $email,
                    'user_pass'  => $password,
                    'role'       => $role,
                ]
            );
            if ( is_wp_error( $id ) ) {
                WP_CLI::error( $id->get_error_message() );
            }
            $state['users'][ $key ] = [
                'id'    => $id,
                'login' => "{$key}-{$domain}",
                'email' => $email,
            ];
            foreach ( $statuses as $status ) {
                $order = new WC_Order();
                $order->set_customer_id( $id );
                $order->set_billing_email( $email );
                $order->set_total( 20 );
                $order->save();
                $order->update_status( 'refunded' === $status ? 'completed' : $status );
                if ( 'refunded' === $status ) {
                    wc_create_refund(
                        [
                            'order_id' => $order->get_id(),
                            'amount'   => 20,
                        ]
                    );
                    $order = wc_get_order( $order->get_id() );
                    $order->update_status( 'refunded' );
                }
                $state['orders'][] = $order->get_id();
            }
        }
        // Key => trigger, config, active.
        $setups = [
            'customer'    => [ 'customer_logged_in', [ 'when' => [ 'sign_in' ] ], true ],
            'customer2'   => [ 'customer_logged_in', [ 'when' => [ 'sign_in' ] ], true ],
            'auto'        => [ 'customer_logged_in', [ 'when' => [ 'auto' ] ], true ],
            'user'        => [
                'user_logged_in',
                [
                    'when'  => [ 'sign_in' ],
                    'roles' => [ 'customer' ],
                ],
                true,
            ],
            'user_auto'   => [
                'user_logged_in',
                [
                    'when'  => [ 'auto' ],
                    'roles' => [ 'customer' ],
                ],
                true,
            ],
            'inactive'    => [ 'customer_logged_in', [ 'when' => [ 'sign_in' ] ], false ],
            'returning'   => [ 'customer_logged_in', [ 'when' => [ 'return' ] ], true ],
            'anyone'      => [
                'user_logged_in',
                [
                    'when'  => [ 'sign_in' ],
                    'roles' => [],
                ],
                true,
            ],
            'anyone_auto' => [
                'user_logged_in',
                [
                    'when'  => [ 'auto' ],
                    'roles' => [],
                ],
                true,
            ],
            'staff'       => [
                'user_logged_in',
                [
                    'when'  => [ 'return' ],
                    'roles' => [],
                ],
                true,
            ],
        ];
        foreach ( $setups as $key => list( $trigger, $config, $active ) ) {
            $graph   = [
                'nodes' => [
                    [
                        'id'       => 'trigger',
                        'kind'     => 'trigger',
                        'type'     => 'trigger',
                        'position' => [
                            'x' => 0,
                            'y' => 0,
                        ],
                        'data'     => [
                            'block'  => $trigger,
                            'config' => $config + [ 'days' => 7 ],
                        ],
                    ],
                    [
                        'id'       => 'wait',
                        'kind'     => 'action',
                        'type'     => 'action',
                        'position' => [
                            'x' => 0,
                            'y' => 100,
                        ],
                        'data'     => [
                            'block'  => 'wait',
                            'config' => [
                                'delay' => [
                                    'value' => 1,
                                    'unit'  => 'days',
                                ],
                            ],
                        ],
                    ],
                ],
                'edges' => [
                    [
                        'id'           => 'e1',
                        'source'       => 'trigger',
                        'target'       => 'wait',
                        'sourceHandle' => null,
                    ],
                ],
            ];
            $created = WorkflowStore::create( "check-login {$key}", $graph );
            if ( ! is_array( $created ) ) {
                WP_CLI::error( 'Could not create a temporary workflow.' );
            }
            $state['flows'][ $key ] = (int) $created['id'];
            if ( $active ) {
                $done = WorkflowStore::activate( (int) $created['id'] );
                if ( is_wp_error( $done ) ) {
                    WP_CLI::error( "Workflow {$key}: " . $done->get_error_message() );
                }
            }
        }
        foreach ( [ 1, 2 ] as $n ) {
            $product = new WC_Product_Simple();
            $product->set_name( "check-login {$n}" );
            $product->set_regular_price( '10' );
            $product->set_manage_stock( 1 === $n );
            $product->set_stock_quantity( 1 === $n ? 10 : null );
            $product->set_status( 'publish' );
            $state['products'][] = $product->save();
        }
        // The classic Cart page form, beside the store's block cart.
        $state['cartpage']     = wp_insert_post(
            [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'check-login cart',
                'post_content' => '[woocommerce_cart]',
            ]
        );
        $state['cartpage_url'] = get_permalink( $state['cartpage'] );
        $state['myaccount']    = wc_get_page_permalink( 'myaccount' );
        $state['checkout']     = wc_get_page_permalink( 'checkout' );
        echo 'STATE ', base64_encode( (string) wp_json_encode( $state ) ), "\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.Security.EscapeOutput.OutputNotEscaped
        break;

    case 'runs':
        $runs = [];
        foreach ( $state['flows'] as $key => $flow_id ) {
            $runs[ $key ] = array_map( 'strval', $wpdb->get_col( $wpdb->prepare( 'SELECT subject FROM ' . RunStore::table() . ' WHERE workflow_id = %d ORDER BY id', $flow_id ) ) );
        }
        echo 'RUNS ', wp_json_encode( $runs ), "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        break;

    case 'age':
        $user_id = (int) $state['users'][ $args[2] ]['id'];
        $wpdb->query( $wpdb->prepare( "UPDATE {$claims} SET claimed_at = %s WHERE subject = %s", gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ), 'user:' . $user_id ) );
        if ( null !== LastSeen::get( $user_id ) ) {
            update_user_meta( $user_id, LastSeen::KEY, time() - 8 * DAY_IN_SECONDS );
        }
        break;

    case 'seen':
        $user_id = (int) $state['users'][ $args[2] ]['id'];
        $wpdb->delete( $claims, [ 'subject' => 'user:' . $user_id ] );
        'none' === $args[3] ? delete_user_meta( $user_id, LastSeen::KEY ) : update_user_meta( $user_id, LastSeen::KEY, time() - (int) $args[3] );
        break;

    case 'seenrows':
        $user_id = (int) $state['users'][ $args[2] ]['id'];
        if ( 'count' !== $args[3] ) {
            $wpdb->delete( $claims, [ 'subject' => 'user:' . $user_id ] );
            delete_user_meta( $user_id, LastSeen::KEY );
            add_user_meta( $user_id, LastSeen::KEY, time() - (int) $args[3] );
            add_user_meta( $user_id, LastSeen::KEY, time() - (int) $args[3] - 60 );
        }
        echo 'ROWS ', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $user_id, LastSeen::KEY ) ), "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        break;

    case 'lastseen':
        $seen = LastSeen::get( (int) $state['users'][ $args[2] ]['id'] );
        echo 'SEEN ', null === $seen ? 'none' : time() - $seen, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        break;

    case 'added':
        $user_id = (int) $state['users'][ $args[2] ]['id'];
        if ( isset( $args[3] ) ) {
            update_user_meta( $user_id, CartActivity::META, (int) $args[3] );
        }
        $at = (int) get_user_meta( $user_id, CartActivity::META, true );
        echo 'ADDED ', $at ? $at : 'none', "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        break;

    case 'stock':
        wc_update_product_stock( (int) $state['products'][0], (int) $args[2] );
        break;

    case 'privacy':
        $user     = $state['users'][ $args[2] ];
        $exporter = apply_filters( 'wp_privacy_personal_data_exporters', [] )['yaymatik-pro'] ?? null;
        $eraser   = apply_filters( 'wp_privacy_personal_data_erasers', [] )['yaymatik-pro'] ?? null;
        $export   = $exporter ? call_user_func( $exporter['callback'], $user['email'], 1 ) : [];
        $names    = array_column( $export['data'][0]['data'] ?? [], 'name' );
        $ok( [ 'Last seen on the store', 'Last added to cart' ] === $names, 'Privacy: the export lists last seen and last added to cart (' . implode( ', ', $names ) . ')' );
        $erased = $eraser ? call_user_func( $eraser['callback'], $user['email'], 1 ) : [];
        $ok( ! empty( $erased['items_removed'] ) && null === LastSeen::get( (int) $user['id'] ) && '' === get_user_meta( (int) $user['id'], CartActivity::META, true ), 'Privacy: the eraser removes both' );
        break;

    case 'template':
        $graph = json_decode( (string) file_get_contents( 'tests/Unit/Pro/fixtures/login-no-cart.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
        $flow  = WorkflowStore::create( 'check-login template', $graph );
        $ok( is_array( $flow ), 'Template: the graph is saved' );
        $done = is_array( $flow ) ? WorkflowStore::activate( (int) $flow['id'] ) : null;
        $ok( true === $done, 'Template: it validates with the Pro registry and activates' . ( is_wp_error( $done ) ? ': ' . $done->get_error_message() . ' ' . wp_json_encode( $done->get_error_data() ) : '' ) );
        echo 'FLOW ', is_array( $flow ) ? (int) $flow['id'] : 0, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        break;

    case 'finish':
        $user    = $state['users'][ $args[2] ];
        $flow_id = (int) $args[3];
        $run_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . RunStore::table() . ' WHERE workflow_id = %d AND subject = %s', $flow_id, 'user:' . $user['id'] ) ) );
        // As if the hour had passed: the run's own time and the job that resumes it.
        $past = gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );
        for ( $round = 0; $round < 3; $round++ ) {
            foreach ( $run_ids as $run_id ) {
                $wpdb->query( $wpdb->prepare( 'UPDATE ' . RunStore::table() . ' SET wait_until = %s WHERE id = %d AND wait_until IS NOT NULL', $past, $run_id ) );
                $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}actionscheduler_actions SET scheduled_date_gmt = %s, scheduled_date_local = %s WHERE hook = %s AND status = 'pending' AND args = %s", $past, $past, ActionSchedulerQueue::HOOK, wp_json_encode( [ $run_id ] ) ) );
            }
            if ( 'due' === ( $args[4] ?? '' ) ) {
                break;
            }
            WP_CLI::runcommand( 'action-scheduler run --hooks=' . ActionSchedulerQueue::HOOK . ' --force --quiet', [ 'return' => true ] );
        }
        $steps = [];
        foreach ( $run_ids as $run_id ) {
            $run     = RunStore::find( $run_id );
            $steps[] = $run['status'] . ':' . implode( '>', array_column( YayMatik\Workflow\Core\Storage\RunStepStore::for_run( $run_id ), 'step_id' ) );
        }
        $mails = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'yay_mail_log' AND post_excerpt LIKE %s", '%' . $wpdb->esc_like( $user['email'] ) . '%' ) );
        echo 'RESULT ', implode( ' | ', $steps ), "\n", 'MAILS ', $mails, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        break;

    case 'saved':
        $user_id = (int) $state['users'][ $args[2] ]['id'];
        $cart    = [];
        foreach ( explode( ',', $args[3] ) as $i => $quantity ) {
            $product = wc_get_product( (int) $state['products'][ $i ] );
            if ( (int) $quantity > 0 ) {
                $key          = ( new WC_Cart() )->generate_cart_id( $product->get_id() );
                $cart[ $key ] = [
                    'key'          => $key,
                    'product_id'   => $product->get_id(),
                    'variation_id' => 0,
                    'variation'    => [],
                    'quantity'     => (int) $quantity,
                    'data_hash'    => wc_get_cart_item_data_hash( $product ),
                ];
            }
        }
        update_user_meta( $user_id, '_woocommerce_persistent_cart_' . get_current_blog_id(), [ 'cart' => $cart ] );
        update_user_meta( $user_id, CartActivity::META, time() - (int) $args[4] );
        break;

    case 'twofactor':
        $user_id = (int) $state['users'][ $args[2] ]['id'];
        $key     = Two_Factor_Totp::generate_key();
        update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, $key );
        update_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, [ 'Two_Factor_Totp' ] );
        update_user_meta( $user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Totp' );
        echo 'KEY ', $key, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        break;

    case 'totp':
        echo 'CODE ', Two_Factor_Totp::calc_totp( $args[1] ), "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        break;

    case 'unclaim':
        $wpdb->delete( $claims, [ 'subject' => 'user:' . (int) $args[1] ] );
        break;

    case 'oidc':
        $idp = 'http://host.docker.internal:8180/realms/yay/protocol/openid-connect';
        update_option(
            'openid_connect_generic_settings',
            [
                'login_type'               => 'button',
                'client_id'                => 'wordpress',
                'client_secret'            => 'wp-secret',
                'issuer'                   => 'http://host.docker.internal:8180/realms/yay',
                'scope'                    => 'openid email profile',
                'endpoint_login'           => $idp . '/auth',
                'endpoint_userinfo'        => $idp . '/userinfo',
                'endpoint_token'           => $idp . '/token',
                'endpoint_end_session'     => $idp . '/logout',
                'identity_key'             => 'preferred_username',
                'nickname_key'             => 'preferred_username',
                'email_format'             => '{email}',
                'allow_internal_idp'       => 1,
                'no_sslverify'             => 1,
                'http_request_timeout'     => 10,
                'enable_pkce'              => 1,
                'state_time_limit'         => 180,
                'link_existing_users'      => 1,
                'create_if_does_not_exist' => 1,
                'redirect_user_back'       => 0,
                'redirect_on_logout'       => 0,
                'token_refresh_enable'     => 0,
            ]
        );
        break;

    case 'destroy':
        WP_Session_Tokens::get_instance( (int) $state['users'][ $args[2] ]['id'] )->destroy_all();
        break;

    case 'nocookie':
        add_filter( 'send_auth_cookies', '__return_false' );
        $user     = $state['users']['paid6'];
        $made     = wp_signon(
            [
                'user_login'    => $user['login'],
                'user_password' => $password,
            ]
        );
        $sessions = WP_Session_Tokens::get_instance( (int) $user['id'] )->get_all();
        $ok( ! is_wp_error( $made ) && 1 === count( $sessions ) && ! empty( current( $sessions )['yaymatik_pending'] ), 'N17 setup: a marked session whose cookies were never sent' );
        break;

    case 'checkout':
        // What WC_Checkout::process_customer() does for a new account, after a guest order paid with the same email earlier.
        $email = 'guest@' . $state['domain'];
        $order = new WC_Order();
        $order->set_billing_email( $email );
        $order->set_total( 20 );
        $order->save();
        $order->update_status( 'completed' );
        $cookie = '';
        add_action(
            'set_logged_in_cookie',
            function ( $value ) use ( &$cookie ) {
                $cookie = LOGGED_IN_COOKIE . '=' . rawurlencode( $value );
            }
        );
        $customer_id = wc_create_new_customer( $email, '', $password );
        if ( is_wp_error( $customer_id ) ) {
            WP_CLI::error( $customer_id->get_error_message() );
        }
        wc_set_customer_auth_cookie( $customer_id );
        echo 'COOKIE ', $cookie, "\n", 'ORDER ', $order->get_id(), "\n", 'USER ', $customer_id, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        break;

    case 'edition':
        if ( 'lite' === ( $args[1] ?? '' ) ) {
            update_option( 'yaymatik_dev_edition', 'lite' );
        } else {
            delete_option( 'yaymatik_dev_edition' );
        }
        break;

    case 'cleanup':
        delete_option( 'yaymatik_dev_edition' );
        foreach ( $state['options'] ?? [] as $option => $value ) {
            null === $value ? delete_option( $option ) : update_option( $option, $value );
        }
        $template = array_filter( WorkflowStore::all(), fn( array $flow ) => 'check-login template' === $flow['name'] );
        foreach ( array_merge( $state['flows'] ?? [], array_map( 'intval', array_column( $template, 'id' ) ) ) as $flow_id ) {
            WorkflowStore::deactivate( $flow_id );
            WorkflowStore::delete( $flow_id );
            $wpdb->delete( $claims, [ 'workflow_id' => $flow_id ] );
            $run_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . RunStore::table() . ' WHERE workflow_id = %d', $flow_id ) ) );
            foreach ( $run_ids as $run_id ) {
                as_unschedule_all_actions( ActionSchedulerQueue::HOOK, [ $run_id ], ActionSchedulerQueue::GROUP );
            }
            if ( $run_ids ) {
                RunStore::delete_with_steps( $run_ids );
            }
        }
        $domain = (string) ( $state['domain'] ?? '' );
        if ( '' !== $domain ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            foreach ( get_users( [ 'search' => '*@' . $domain ] ) as $user ) {
                wp_delete_user( $user->ID );
            }
            foreach ( array_merge( $state['orders'] ?? [], array_map( 'intval', array_filter( explode( ',', (string) ( $args[2] ?? '' ) ) ) ) ) as $order_id ) {
                $saved_order = wc_get_order( $order_id );
                if ( $saved_order ) {
                    $saved_order->delete( true );
                }
            }
        }
        if ( ! empty( $state['cartpage'] ) ) {
            wp_delete_post( (int) $state['cartpage'], true );
        }
        foreach ( $state['products'] ?? [] as $product_id ) {
            wp_delete_post( (int) $product_id, true );
        }
        delete_option( 'openid_connect_generic_settings' );
        $sso = get_user_by( 'login', 'kc-buyer' );
        if ( $sso ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user( $sso->ID );
        }
        remove_role( 'check_login_buyer' );
        remove_role( 'check_login_editor' );
        break;

    default:
        WP_CLI::error( "Unknown phase: {$phase}" );
}

if ( $fails ) {
    exit( 1 );
}
