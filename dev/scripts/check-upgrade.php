<?php
/**
 * Data continuity when YayMatik's files are replaced (wp-cli on the throwaway site check-upgrade.sh makes), one phase per call:
 *   seed                   settings and three workflows (order paid and review posted active, one inactive), on the old build
 *   events order|review|rest a paid order, an approved review, then runs the queue (a run waits, the review claim is held),
 *                          adds a tracked cart and an unsubscribe
 *   snapshot <file>        every `yaymatik%` option, row count + checksum of each YayMatik table, YayMatik user meta
 *   verify <file> <edition> compares with the snapshot (`lite` or `pro`); the review again is refused by its claim
 *   finish                 the waiting run's time is up: it must complete
 *   starts / started <n>   a new paid order; the active workflow must have started one more run
 *   pro <template.json>    resume time set, the login template validates and activates
 * Prints PASS / FAIL lines. Run through check-upgrade.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.AlternativeFunctions

use YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Core\Storage\CartStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\Schema;
use YayMatik\Workflow\Core\Storage\Settings;
use YayMatik\Workflow\Core\Storage\UnsubscribeStore;
use YayMatik\Workflow\Core\Storage\WorkflowStore;

global $wpdb;
$phase  = $args[0] ?? '';
$tables = [ Schema::WORKFLOWS, Schema::WORKFLOW_VERSIONS, Schema::RUNS, Schema::RUN_STEPS, Schema::UNSUBSCRIBES, Schema::CARTS, Schema::TRIGGER_CLAIMS, Schema::RUN_EMAILS ];

// Keys the new build may write on its first requests, and why; anything else that changed is a failure.
$expected = [
    // Pro adds its daily inactive sweep, so the daily job list changed: Cleanup records it and checks the jobs at once.
    'yaymatik_cleanup_jobs',
    'yaymatik_cleanup_checked_at',
    // Pro's cart tracking state, written on its first licensed boot (D8).
    'yaymatik_cart_tracking_resumed_at',
    'yaymatik_cart_tracking_licensed',
    'yaymatik_cart_tracking_paused',
];

$fails = 0;
$ok    = function ( bool $pass, string $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $fails += $pass ? 0 : 1;
};

/**
 * A two-step graph: the trigger, then a Wait of `$days` days, then an email to the customer.
 *
 * @param string $trigger
 * @param int    $days
 */
$graph = function ( string $trigger, int $days ): array {
    $node = fn( string $id, string $kind, int $y, string $block, array $config ) => [
        'id'       => $id,
        'kind'     => $kind,
        'type'     => $kind,
        'position' => [
            'x' => 0,
            'y' => $y,
        ],
        'data'     => [
            'block'  => $block,
            'config' => $config,
        ],
    ];
    return [
        'nodes' => [
            $node( 'trigger', 'trigger', 0, $trigger, [] ),
            $node(
                'wait',
                'action',
                100,
                'wait',
                [
                    'delay' => [
                        'value' => $days,
                        'unit'  => 'days',
                    ],
                ]
            ),
            $node(
                'email',
                'action',
                200,
                'send_email',
                [
                    'to'      => '{{ customer.email }}',
                    'subject' => 'Thanks from {{ site.name }}',
                    'heading' => 'Thanks',
                    'message' => '<p>Hi {{ customer.first_name | default: "there" }},</p><p>Thanks.</p>',
                ]
            ),
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
                'target' => 'email',
            ],
        ],
    ];
};

$paid_order = function ( string $email ): int {
    $order = new WC_Order();
    $order->set_billing_email( $email );
    $order->set_billing_first_name( 'Upgrade' );
    $order->set_total( 30 );
    $order->save();
    $order->update_status( 'processing' );
    return $order->get_id();
};

$run_queue = function () {
    WP_CLI::runcommand( 'action-scheduler run --hooks=' . ActionSchedulerQueue::HOOK . ' --force --quiet', [ 'return' => true ] );
};

$runs_of = fn( int $workflow_id ) => array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . RunStore::table() . ' WHERE workflow_id = %d ORDER BY id', $workflow_id ) ) );

$snapshot = function () use ( $wpdb, $tables ): array {
    $options = [];
    foreach ( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'yaymatik%' ORDER BY option_name", ARRAY_A ) as $row ) {
        $options[ $row['option_name'] ] = $row['option_value'];
    }
    $rows = [];
    foreach ( $tables as $name ) {
        $table          = Schema::table( $name );
        $rows[ $table ] = [
            'count'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
            'checksum' => (string) $wpdb->get_row( "CHECKSUM TABLE {$table}", ARRAY_A )['Checksum'],
        ];
    }
    $meta = [];
    foreach ( $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE 'yaymatik%' OR meta_key LIKE '\\_yaymatik%' ORDER BY umeta_id", ARRAY_A ) as $row ) {
        $meta[ $row['user_id'] . '|' . $row['meta_key'] ] = $row['meta_value'];
    }
    return [
        'options' => $options,
        'tables'  => $rows,
        'meta'    => $meta,
    ];
};

switch ( $phase ) {
    case 'seed':
        foreach ( WC()->mailer()->get_emails() as $wc_email ) {
            add_filter( 'woocommerce_email_enabled_' . $wc_email->id, '__return_false' );
        }
        $saved = Settings::update(
            [
                'cart_tracking'       => true,
                'capture_guest_email' => false,
                'cart_retention_days' => 30,
                'run_retention_days'  => 45,
                'unsubscribe_text'    => 'Stop these emails',
            ]
        );
        $ok( ! is_wp_error( $saved ), 'Seed: settings saved' . ( is_wp_error( $saved ) ? ': ' . $saved->get_error_message() : '' ) );

        $flows = [];
        foreach ( [
            'paid'     => [ 'order_paid', true ],
            'review'   => [ 'review_posted', true ],
            'inactive' => [ 'order_paid', false ],
        ] as $key => list( $trigger, $active ) ) {
            $created = WorkflowStore::create( "upgrade {$key}", $graph( $trigger, 2 ) );
            $done    = is_array( $created ) && $active ? WorkflowStore::activate( (int) $created['id'] ) : true;
            $ok( is_array( $created ) && true === $done, "Seed: workflow {$key}" . ( is_wp_error( $done ) ? ': ' . $done->get_error_message() : '' ) );
            $flows[ $key ] = is_array( $created ) ? (int) $created['id'] : 0;
        }
        update_option( 'yaymatik_upgrade_check', $flows, false );
        break;

    case 'events':
        // Separate wp-cli calls: the Dispatcher starts runs at the end of the process that collected the event.
        $what = $args[1] ?? '';
        if ( 'order' === $what ) {
            echo 'ORDER ', $paid_order( 'waiting@upgrade.test' ), "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } elseif ( 'review' === $what ) {
            $product = new WC_Product_Simple();
            $product->set_name( 'Upgrade product' );
            $product->set_regular_price( '10' );
            $product->set_status( 'publish' );
            $product_id = $product->save();
            $review_id  = wp_insert_comment(
                [
                    'comment_post_ID'      => $product_id,
                    'comment_author'       => 'Reviewer',
                    'comment_author_email' => 'reviewer@upgrade.test',
                    'comment_content'      => 'Good.',
                    'comment_type'         => 'review',
                    'comment_approved'     => 1,
                ]
            );
            update_option( 'yaymatik_upgrade_review', (int) $review_id, false );
        } elseif ( 'rest' === $what ) {
            $run_queue();
            CartStore::upsert(
                't_upgrade',
                [
                    'email'            => 'cart@upgrade.test',
                    'first_name'       => 'Cart',
                    'items'            => (string) wp_json_encode( [] ),
                    'total'            => 12.5,
                    'currency'         => 'USD',
                    'last_activity_at' => current_time( 'mysql', true ),
                ]
            );
            UnsubscribeStore::add( 'gone@upgrade.test' );
            $flows   = get_option( 'yaymatik_upgrade_check' );
            $waiting = array_filter( array_map( [ RunStore::class, 'find' ], $runs_of( (int) $flows['paid'] ) ), fn( $run ) => RunStore::STATUS_WAITING === $run['status'] );
            $ok( 1 === count( $waiting ), 'Seed: one run waits on its Wait step' );
            $claims = Schema::table( Schema::TRIGGER_CLAIMS );
            $ok( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$claims} WHERE workflow_id = %d", $flows['review'] ) ), 'Seed: the review claim is held' );
        }
        break;

    case 'snapshot':
        file_put_contents( $args[1], (string) wp_json_encode( $snapshot(), JSON_PRETTY_PRINT ) );
        break;

    case 'verify':
        $before  = json_decode( (string) file_get_contents( $args[1] ), true );
        $edition = $args[2] ?? 'lite';
        $after   = $snapshot();
        $changed = [];
        foreach ( [ 'options', 'tables', 'meta' ] as $part ) {
            foreach ( array_unique( array_merge( array_keys( $before[ $part ] ), array_keys( $after[ $part ] ) ) ) as $key ) {
                if ( ( $before[ $part ][ $key ] ?? null ) !== ( $after[ $part ][ $key ] ?? null ) ) {
                    $changed[] = "{$part}:{$key}";
                }
            }
        }
        $unexpected = array_values( array_filter( $changed, fn( string $key ) => ! in_array( substr( $key, strpos( $key, ':' ) + 1 ), $expected, true ) ) );
        echo 'CHANGED ', implode( ', ', $changed ), "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        $ok( ! $unexpected, 'Snapshot: equal apart from the expected keys' . ( $unexpected ? ' (unexpected: ' . implode( ', ', $unexpected ) . ')' : '' ) );
        $ok( '8' === get_option( 'yaymatik_db_version' ), 'yaymatik_db_version is still 8 (' . get_option( 'yaymatik_db_version' ) . ')' );

        $flows = get_option( 'yaymatik_upgrade_check' );
        // The review again: unapproved, then approved, is a new event for the held claim.
        $review_runs = count( $runs_of( (int) $flows['review'] ) );
        wp_set_comment_status( (int) get_option( 'yaymatik_upgrade_review' ), 'hold' );
        wp_set_comment_status( (int) get_option( 'yaymatik_upgrade_review' ), 'approve' );
        do_action( 'shutdown' );
        $ok( count( $runs_of( (int) $flows['review'] ) ) === $review_runs, 'A new event for the held claim is refused' );
        // The same workflow still takes another review: the event above did reach it.
        $review = get_comment( (int) get_option( 'yaymatik_upgrade_review' ) );
        wp_insert_comment(
            [
                'comment_post_ID'      => (int) $review->comment_post_ID,
                'comment_author'       => 'Second reviewer',
                'comment_author_email' => 'second@upgrade.test',
                'comment_content'      => 'Also good.',
                'comment_type'         => 'review',
                'comment_approved'     => 1,
            ]
        );
        do_action( 'shutdown' );
        $ok( count( $runs_of( (int) $flows['review'] ) ) === $review_runs + 1, '… while a new review on the same workflow starts a run' );
        break;

    case 'finish':
        // The waiting run's time is up: it goes on to its email.
        $flows = get_option( 'yaymatik_upgrade_check' );
        $past  = gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . RunStore::table() . ' SET wait_until = %s WHERE workflow_id = %d AND wait_until IS NOT NULL', $past, $flows['paid'] ) );
        $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}actionscheduler_actions SET scheduled_date_gmt = %s, scheduled_date_local = %s WHERE hook = %s AND status = 'pending'", $past, $past, ActionSchedulerQueue::HOOK ) );
        $run_queue();
        $first = RunStore::find( $runs_of( (int) $flows['paid'] )[0] );
        $ok( RunStore::STATUS_COMPLETED === $first['status'], "The waiting run finishes after its due action runs ({$first['status']})" );
        break;

    case 'starts':
        $flows = get_option( 'yaymatik_upgrade_check' );
        echo 'BEFORE ', count( $runs_of( (int) $flows['paid'] ) ), "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        $paid_order( 'after@upgrade.test' );
        break;

    case 'started':
        $flows = get_option( 'yaymatik_upgrade_check' );
        $ok( count( $runs_of( (int) $flows['paid'] ) ) === (int) $args[1] + 1, 'The active workflow still starts runs' );
        break;

    case 'pro':
        $resumed = (int) get_option( 'yaymatik_cart_tracking_resumed_at' );
        $ok( $resumed > time() - HOUR_IN_SECONDS, 'Pro: yaymatik_cart_tracking_resumed_at is set' );
        $template = json_decode( (string) file_get_contents( $args[1] ), true );
        $created  = WorkflowStore::create( 'upgrade login template', $template );
        $done     = is_array( $created ) ? WorkflowStore::activate( (int) $created['id'] ) : null;
        $ok( true === $done, 'Pro: the login template validates and activates' . ( is_wp_error( $done ) ? ': ' . $done->get_error_message() : '' ) );
        break;

    default:
        WP_CLI::error( "Unknown phase: {$phase}" );
}

if ( $fails ) {
    exit( 1 );
}
