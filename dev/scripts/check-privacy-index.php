<?php
/**
 * The privacy index against the full scan it replaced: runs written through the real stores, then every lookup
 * compared with `lib/privacy-old-scan.php` before and after an erase and a cleanup delete. Prints PASS / FAIL
 * lines; removes its runs. Run through check-privacy-index.sh.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

require_once __DIR__ . '/lib/privacy-old-scan.php';

use YayMatik\Engine\Privacy\PersonalData;
use YayMatik\Workflow\Core\Runtime\Cancellation;
use YayMatik\Workflow\Core\Runtime\PersonSubjects;
use YayMatik\Workflow\Core\Runtime\Runtime;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Core\Storage\CartStore;
use YayMatik\Workflow\Core\Storage\Settings;
use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\Schema;
use YayMatik\Workflow\Core\Storage\TriggerClaimStore;

global $wpdb;
$fails = 0;
$ok    = function ( bool $pass, string $what ) use ( &$fails ) {
    echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $fails += $pass ? 0 : 1;
};

// A workflow id no real workflow has; a domain no other run mentions.
const PIDX_WORKFLOW = 999997;
$domain  = 'pidx-' . wp_generate_password( 6, false ) . '.test';
$cleanup = function () use ( $wpdb ) {
    RunStore::delete_with_steps( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE workflow_id = %d', RunStore::table(), PIDX_WORKFLOW ) ) ) );
};
$cleanup();
register_shutdown_function( $cleanup );

$bob   = "bob@{$domain}";
$ann   = "ann@{$domain}";
$oneil = "o'neil@{$domain}";
$long  = str_repeat( 'l', 70 ) . "@{$domain}";
$guest = "guest@{$domain}";
$run   = fn( array $scope, string $subject = '' ) => RunStore::create(
    [
        'workflow_id' => PIDX_WORKFLOW,
        'version_id'  => 1,
        'trigger_id'  => 'check',
        'subject'     => $subject,
        'scope'       => $scope,
    ]
);

// Each way an email reaches a run, through the store call that writes it.
$seeded = [
    'scope, mixed case'       => $run( [ 'to' => strtoupper( $bob ) ] ),
    'scope, as a key'         => $run( [ 'sent' => [ $bob => 1 ] ] ),
    'scope, inside a string'  => $run( [ 'note' => "Hi {$bob}" ] ),
    'scope, claim key'        => $run( [ 'trigger' => [ 'claim_key' => TriggerClaimStore::email_key( $guest ) ] ] ),
    'scope, other domain'     => $run( [ 'to' => "jim{$bob}" ] ),
    'error, sentence end'     => $run( [] ),
    'error, .au'              => $run( [] ),
    'error, apostrophe'       => $run( [] ),
    'step input'              => $run( [] ),
    'step output and error'   => $run( [] ),
    'step closed with reason' => $run( [] ),
    'cancelled while waiting' => $run( [] ),
    'about a cart, waiting'   => $run( [], 'cart:999997001' ),
    'about a cart, finished'  => $run( [], 'cart:999997001' ),
    'long local part'         => $run( [ 'to' => $long ] ),
];
RunStore::save( $seeded['error, sentence end'], [ 'error' => "Bounce from {$bob}." ] );
RunStore::save( $seeded['error, .au'], [ 'error' => "Bounce from {$bob}.au" ] );
RunStore::save( $seeded['error, apostrophe'], [ 'error' => "Mail for {$oneil} failed." ] );
RunStepStore::start( $seeded['step input'], 'n1', [ 'to' => $ann ] );
$step = RunStepStore::start( $seeded['step output and error'], 'n1', [] );
RunStepStore::finish( $step, RunStepStore::STATUS_FAILED, [ 'to' => $ann ], "Could not send to <{$bob}>" );
RunStepStore::start( $seeded['step closed with reason'], 'n1', [] );
RunStepStore::close_open( $seeded['step closed with reason'], RunStepStore::STATUS_CANCELLED, "Unsubscribed: {$ann}" );
RunStore::save( $seeded['cancelled while waiting'], [ 'status' => RunStore::STATUS_WAITING ] );
RunStepStore::finish( RunStepStore::start( $seeded['cancelled while waiting'], 'wait', [] ), RunStepStore::STATUS_WAITING );
RunStore::cancel_waiting( [ $seeded['cancelled while waiting'] ], "Stopped for {$ann}." );
RunStepStore::cancel_waiting( [ $seeded['cancelled while waiting'] ], "Stopped for {$ann}." );
RunStore::save( $seeded['about a cart, waiting'], [ 'status' => RunStore::STATUS_WAITING ] );
RunStore::save( $seeded['about a cart, finished'], [ 'status' => RunStore::STATUS_COMPLETED ] );
// Erase leaves queued and running runs alone; two stay queued for in_progress_mentioning.
foreach ( array_diff_key( $seeded, array_flip( [ 'scope, inside a string', 'scope, other domain', 'cancelled while waiting', 'about a cart, waiting', 'about a cart, finished' ] ) ) as $id ) {
    RunStore::save( $id, [ 'status' => RunStore::STATUS_COMPLETED ] );
}

$ids     = fn( array $rows ) => array_map( 'intval', array_column( $rows, 'id' ) );
$people  = [ $bob, "{$bob}.au", "jim{$bob}", $ann, $oneil, "neil@{$domain}", $long, $guest, "nobody@{$domain}" ];
$compare = function ( string $when ) use ( $ok, $ids, $people ) {
    foreach ( $people as $email ) {
        foreach ( [ [], [ 'cart:999997001' ] ] as $subjects ) {
            $label = $email . ( $subjects ? ' + cart' : '' );
            $ok( $ids( yaymatik_old_for_export( $email, $subjects, 1000, 0 ) ) === $ids( RunStore::for_export( $email, $subjects, 1000, 0 ) ), "{$when}: for_export {$label}" );
            $ok( $ids( yaymatik_old_for_export( $email, $subjects, 2, 1 ) ) === $ids( RunStore::for_export( $email, $subjects, 2, 1 ) ), "{$when}: for_export page 2 of 1-row pages, {$label}" );
            $ok( yaymatik_old_ids_mentioning( $email, $subjects, 1000 ) === RunStore::ids_mentioning( $email, $subjects, 1000 ), "{$when}: ids_mentioning {$label}" );
            $ok( yaymatik_old_in_progress_mentioning( $email, $subjects ) === RunStore::in_progress_mentioning( $email, $subjects ), "{$when}: in_progress_mentioning {$label}" );
        }
    }
};

$found = fn( string $email ) => $ids( RunStore::for_export( $email, [], 1000, 0 ) );
$ok( [ $seeded['scope, mixed case'], $seeded['scope, as a key'], $seeded['error, sentence end'], $seeded['step output and error'] ] === $found( $bob ), 'bob: whole JSON strings, a key, a sentence-final dot, a step error; not inside a longer string' );
$ok( [ $seeded['error, .au'] ] === $found( "{$bob}.au" ) && [ $seeded['scope, other domain'] ] === $found( "jim{$bob}" ), 'bob@….au and jimbob@ are other people' );
$ok( [ $seeded['error, apostrophe'] ] === $found( $oneil ) && [ $seeded['error, apostrophe'] ] === $found( "neil@{$domain}" ), "o'neil@ found, and neil@ as the old REGEXP did" );
$ok( [ $seeded['step input'], $seeded['step output and error'], $seeded['step closed with reason'], $seeded['cancelled while waiting'] ] === $found( $ann ), 'ann: step input, output, a close reason, a cancel reason' );
$ok( [ $seeded['scope, claim key'] ] === $found( $guest ) && [ $seeded['long local part'] ] === $found( $long ), 'a guest by claim key; a 70-character local part' );
$compare( 'seeded' );

$privacy = PersonalData::get_instance();
for ( $page = 1, $erase = [ 'done' => false ]; ! $erase['done'] && $page <= 5; $page++ ) {
    $erase = $privacy->erase( $bob, $page );
}
$ok( $erase['done'] && [] === RunStore::ids_mentioning( $bob, [], 1000 ) && [] === yaymatik_old_ids_mentioning( $bob, [], 1000 ), 'erase bob: done, nothing left by either lookup' );
$ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE email = %s', Schema::table( Schema::RUN_EMAILS ), $bob ) ), 'erase bob: no index row left' );
$compare( 'after erase' );

RunStore::delete_with_steps( [ $seeded['step input'], $seeded['long local part'] ] );
$ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE run_id IN (%d, %d)', Schema::table( Schema::RUN_EMAILS ), $seeded['step input'], $seeded['long local part'] ) ), 'delete: the runs\' index rows gone' );
$compare( 'after delete' );

// An index row a failed delete left for the next id must not list the run that gets that id.
$next   = (int) $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', RunStore::table() ), ARRAY_A )['Auto_increment'];
$orphan = "orphan@{$domain}";
$wpdb->insert(
    Schema::table( Schema::RUN_EMAILS ),
    [
        'run_id' => $next,
        'email'  => $orphan,
    ]
);
$reused = $run( [ 'note' => 'no email here' ] );
$ok( $next === $reused && [] === RunStore::for_export( $orphan, [], 1000, 0 ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE email = %s', Schema::table( Schema::RUN_EMAILS ), $orphan ) ), 'an orphan index row for the next id: dropped when that run is created, export lists nothing' );

// A Skipped cart run, recorded by the Dispatcher: found by its cart while the cart lives, and holding no email.
wp_set_current_user( 1 );
$settings = Settings::all();
Settings::update( [ 'cart_tracking' => true ] );
$rest     = function ( string $method, string $route, ?array $body = null ) {
    $request = new WP_REST_Request( $method, '/yaymatik/v1/' . $route );
    if ( null !== $body ) {
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( wp_json_encode( $body ) );
    }
    return rest_do_request( $request );
};
$node     = fn( string $id, string $kind, string $block, array $config, int $y ) => [
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
$flow     = (int) $rest(
    'POST',
    'workflows',
    [
        'name'  => 'Check privacy index (temporary)',
        'graph' => [
            'nodes' => [
                $node( 'trigger', 'trigger', 'cart_abandoned', [], 0 ),
                $node(
                    'mail',
                    'action',
                    'send_email',
                    [
                        'to'      => '{{ customer.email }}',
                        'subject' => 'Check',
                        'heading' => 'Hi',
                        'message' => '<p>Hi</p>',
                    ],
                    170
                ),
            ],
            'edges' => [
                [
                    'id'     => 'e1',
                    'source' => 'trigger',
                    'target' => 'mail',
                ],
            ],
        ],
    ]
)->get_data()['id'];
$ok( 200 === $rest( 'POST', "workflows/{$flow}/activate" )->get_status(), 'skipped run: a temporary Cart abandoned workflow activates' );
$shopper = "skipped@{$domain}";
CartStore::upsert(
    'pidx-' . $domain,
    [
        'email' => $shopper,
        'items' => '[{"product_id":1,"quantity":1}]',
    ]
);
$cart = CartStore::for_email( $shopper )[0] ?? [ 'id' => 0 ];
$wpdb->update( Schema::table( Schema::CARTS ), [ 'status' => CartStore::ORDERED ], [ 'id' => (int) $cart['id'] ] );
Runtime::dispatcher()->collect( StepRegistry::get( 'cart_abandoned' ), [ 'cart_id' => (int) $cart['id'] ] );
Runtime::dispatcher()->flush();
$skipped = RunStore::list(
    [
        'workflow_id' => $flow,
        'status'      => 'skipped',
    ]
)[0] ?? [ 'id' => 0 ];
$ok( $skipped['id'] && Cancellation::order_placed( 0 ) === $skipped['error'], 'skipped run: recorded with the ordered-cart reason' );
$subjects = PersonSubjects::for_email( $shopper );
$exported = fn() => array_column( $privacy->export( $shopper, 1 )['data'], 'item_id' );
$ok( in_array( "yaymatik-run-{$skipped['id']}", $exported(), true ), 'skipped run: export lists it, by its cart' );
$ok( $ids( yaymatik_old_for_export( $shopper, $subjects, 1000, 0 ) ) === $ids( RunStore::for_export( $shopper, $subjects, 1000, 0 ) ) && yaymatik_old_ids_mentioning( $shopper, $subjects, 1000 ) === RunStore::ids_mentioning( $shopper, $subjects, 1000 ), 'skipped run: export and erase lookups match the old scan' );
$erase = $privacy->erase( $shopper, 1 );
$row   = RunStore::find( (int) $skipped['id'] );
$ok( $erase['done'] && [] === CartStore::for_email( $shopper ) && ! in_array( "yaymatik-run-{$skipped['id']}", $exported(), true ), 'skipped run: erase deletes the cart; export no longer finds the run' );
$ok( $row && false === stripos( wp_json_encode( $row ), $shopper ), 'skipped run: kept, with no email in it' );
$rest( 'POST', "workflows/{$flow}/deactivate" );
$ok( 200 === $rest( 'DELETE', "workflows/{$flow}" )->get_status(), 'skipped run: the temporary workflow deleted' );
// By subject: the site's own active cart workflows recorded skipped runs for this cart too.
RunStore::delete_with_steps( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE subject = %s', RunStore::table(), 'cart:' . (int) $cart['id'] ) ) ) );
Settings::update( [ 'cart_tracking' => $settings['cart_tracking'] ] );

$cleanup();
echo $fails ? "{$fails} failed\n" : "All passed\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
exit( $fails ? 1 : 0 );
