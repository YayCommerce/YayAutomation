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
 *   nocookie <state>       a sign-in whose cookies are never sent (`send_auth_cookies` false)
 *   checkout <state>       replays checkout's account creation for a guest with an older paid order; prints `COOKIE <name>=<value>`
 *   edition <lite|pro>     the dev license on or off
 *   cleanup <state> [ids]  removes everything setup made, and these orders
 * Prints PASS / FAIL lines. Run through check-login.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited

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
            $wpdb->update( $claims, [ 'claimed_at' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ], [ 'workflow_id' => $ghost, 'subject' => $subject ] );
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
        $first  = TriggerClaimStore::claim( $ghost, $subject, DAY_IN_SECONDS );
        $code   = sprintf( '$t = microtime( true ); $won = YayMatik\Workflow\Core\Storage\TriggerClaimStore::claim( %d, %s, DAY_IN_SECONDS ); echo (int) $won, " ", round( microtime( true ) - $t, 1 );', $ghost, var_export( $subject, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
        $second = proc_open( [ 'wp', 'eval', $code ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
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
            'woocommerce_enable_myaccount_registration' => 'yes',
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
            'paid'      => [ 'customer', [ 'completed' ] ],
            'paid2'     => [ 'customer', [ 'processing' ] ],
            'paid3'     => [ 'customer', [ 'completed' ] ],
            'paid4'     => [ 'customer', [ 'completed' ] ],
            'paid5'     => [ 'customer', [ 'completed' ] ],
            'paid6'     => [ 'customer', [ 'completed' ] ],
            'noorder'   => [ 'customer', [] ],
            'unpaid'    => [ 'customer', [ 'failed', 'cancelled', 'refunded' ] ],
            'manager'   => [ 'shop_manager', [ 'completed' ] ],
            'buyer'     => [ 'check_login_buyer', [ 'completed' ] ],
            'editor'    => [ 'check_login_editor', [ 'completed' ] ],
            'subscriber' => [ 'subscriber', [] ],
            'ret'        => [ 'customer', [ 'completed' ] ],
            'ret2'       => [ 'customer', [ 'completed' ] ],
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
            'customer'  => [ 'customer_logged_in', [ 'when' => [ 'sign_in' ] ], true ],
            'customer2' => [ 'customer_logged_in', [ 'when' => [ 'sign_in' ] ], true ],
            'auto'      => [ 'customer_logged_in', [ 'when' => [ 'auto' ] ], true ],
            'user'      => [
                'user_logged_in',
                [
                    'when'  => [ 'sign_in' ],
                    'roles' => [ 'customer' ],
                ],
                true,
            ],
            'user_auto' => [
                'user_logged_in',
                [
                    'when'  => [ 'auto' ],
                    'roles' => [ 'customer' ],
                ],
                true,
            ],
            'inactive'  => [ 'customer_logged_in', [ 'when' => [ 'sign_in' ] ], false ],
            'returning' => [ 'customer_logged_in', [ 'when' => [ 'return' ] ], true ],
            'staff'     => [
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
        $state['myaccount'] = wc_get_page_permalink( 'myaccount' );
        $state['checkout']  = wc_get_page_permalink( 'checkout' );
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

    case 'lastseen':
        $seen = LastSeen::get( (int) $state['users'][ $args[2] ]['id'] );
        echo 'SEEN ', null === $seen ? 'none' : time() - $seen, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        break;

    case 'destroy':
        WP_Session_Tokens::get_instance( (int) $state['users'][ $args[2] ]['id'] )->destroy_all();
        break;

    case 'nocookie':
        add_filter( 'send_auth_cookies', '__return_false' );
        $user = $state['users']['paid6'];
        $made = wp_signon(
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
        foreach ( $state['flows'] ?? [] as $flow_id ) {
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
        remove_role( 'check_login_buyer' );
        remove_role( 'check_login_editor' );
        break;

    default:
        WP_CLI::error( "Unknown phase: {$phase}" );
}

if ( $fails ) {
    exit( 1 );
}
