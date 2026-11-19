<?php
namespace YayMatik\Tests\Unit\Pro\Workflow\Core\Session;

use PHPUnit\Framework\TestCase;
use YayMatik\Pro\Workflow\Core\Session\LastSeen;
use YayMatik\Pro\Workflow\Core\Session\SessionSignals;
use YayMatik\Tests\Support\FakeWpdb;

require_once __DIR__ . '/session-stubs.php';

/** Sign-in and return: the mark on a new session, its confirmation on a later request, a visit after a long absence; one event for every subscriber. */
final class SessionSignalsTest extends TestCase {

    /** @var SessionSignals */
    private $signals;

    /** @var array[] Events per subscriber. */
    private $events = [ [], [] ];

    /** @var FakeWpdb */
    private $db;

    /** @var array[] Stub hooks before the test, for tearDown(). */
    private $hooks;

    protected function setUp(): void {
        $this->hooks   = [ $GLOBALS['yaymatik_test_filters'] ?? [], $GLOBALS['yaymatik_test_actions'] ?? [] ];
        $this->signals = ( new \ReflectionClass( SessionSignals::class ) )->newInstanceWithoutConstructor();
        foreach ( [ 0, 1 ] as $i ) {
            $this->signals->subscribe(
                function ( array $args ) use ( $i ) {
                    $this->events[ $i ][] = $args;
                }
            );
        }
        $this->db = new FakeWpdb();
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
        $GLOBALS['wpdb'] = $this->db;
    }

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_users'], $GLOBALS['yaymatik_test_sessions'], $GLOBALS['yaymatik_test_session_token'], $GLOBALS['yaymatik_test_user_id'], $GLOBALS['yaymatik_test_user_meta_added'] );
        list( $GLOBALS['yaymatik_test_filters'], $GLOBALS['yaymatik_test_actions'] ) = $this->hooks;
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    /**
     * Runs `init` for a request signed in with this session.
     *
     * @param int    $user_id
     * @param string $token
     */
    private function next_request( int $user_id, string $token ): void {
        $GLOBALS['yaymatik_test_user_id']       = $user_id;
        $GLOBALS['yaymatik_test_session_token'] = $token;
        $this->signals->confirm();
    }

    public function test_hooks_are_added_once_for_both_subscribers(): void {
        $added = array_filter( $GLOBALS['yaymatik_test_filters']['attach_session_information'] ?? [], fn( $callback ) => is_array( $callback ) && $callback[0] === $this->signals );
        $this->assertCount( 1, $added );
    }

    public function test_a_sign_in_is_marked_and_confirmed_once_on_the_next_request(): void {
        $this->next_request( 0, '' );
        $session = $this->signals->mark( [ 'login' => 1 ], 7 );
        $this->assertSame( 1, $session[ SessionSignals::PENDING ] );
        $this->assertSame( 'sign_in', $session[ SessionSignals::HOW ] );

        $GLOBALS['yaymatik_test_sessions'][7]['tok'] = $session;
        $this->db->results                           = [ 1 ];
        $this->next_request( 7, 'tok' );
        $this->next_request( 7, 'tok' );

        $this->assertCount( 1, $this->events[0], 'N5: browsing on is no second event' );
        $this->assertSame( $this->events[0], $this->events[1], 'one confirmation for both triggers' );
        $this->assertSame( [ 'user_id', 'how', 'time' ], array_keys( $this->events[0][0] ) );
        $this->assertSame( 'sign_in', $this->events[0][0]['how'] );
        $this->assertSame( [ 'login' => 1 ], $GLOBALS['yaymatik_test_sessions'][7]['tok'], 'the mark is cleared' );
        $this->assertStringContainsString( "meta_key = '_yaymatik_last_seen'", $this->db->last_sql(), 'last seen set' );
    }

    public function test_a_sign_up_is_marked_auto(): void {
        $this->signals->registered( 7 );
        $this->assertSame( 'auto', $this->signals->mark( [], 7 )[ SessionSignals::HOW ] );
    }

    public function test_switching_users_or_reissuing_the_signed_in_cookie_is_no_sign_in(): void {
        $this->assertArrayNotHasKey( SessionSignals::PENDING, $this->signals->mark( [ 'switched_from_id' => 1 ], 7 ), 'N16' );

        $this->next_request( 7, 'old' );
        $this->assertArrayNotHasKey( SessionSignals::PENDING, $this->signals->mark( [], 7 ), 'a password reset while signed in' );
        $this->assertArrayHasKey( SessionSignals::PENDING, $this->signals->mark( [], 8 ), 'someone else' );
    }

    public function test_a_session_made_in_this_request_is_not_confirmed_in_it(): void {
        $GLOBALS['yaymatik_test_sessions'][7]['new'] = $this->signals->mark( [], 7 );
        $this->signals->created( '', 0, 0, 7, 'logged_in', 'new' );
        $this->next_request( 7, 'new' );

        $this->assertSame( [], $this->events[0] );
    }

    public function test_a_user_switch_clears_the_marks_of_this_request(): void {
        $GLOBALS['yaymatik_test_sessions'][7]['new'] = $this->signals->mark( [], 7 );
        $this->signals->created( '', 0, 0, 7, 'logged_in', 'new' );
        $this->signals->unmark_created();

        $this->assertSame( [], $GLOBALS['yaymatik_test_sessions'][7]['new'] );
    }

    public function test_an_unmarked_or_signed_out_request_does_nothing(): void {
        $GLOBALS['yaymatik_test_sessions'][7]['tok'] = [ 'login' => 1 ];
        $this->next_request( 7, 'tok' );
        $this->next_request( 0, '' );

        $this->assertSame( [], $this->events[0] );
        $this->assertSame( [], $this->db->calls );
    }

    public function test_last_seen_moves_only_when_older_and_tells_who_moved_it(): void {
        $this->db->results = [ 1, 0 ];
        $this->assertTrue( LastSeen::advance( 7, 1000, 900 ) );
        $this->assertSame( "UPDATE `wp_usermeta` SET meta_value = 1000 WHERE user_id = 7 AND meta_key = '_yaymatik_last_seen' AND CAST( meta_value AS UNSIGNED ) < 100", $this->db->last_sql() );
        $this->assertFalse( LastSeen::advance( 7, 1000, 900 ), 'the parallel request lost' );
    }

    public function test_last_seen_is_added_the_first_time(): void {
        $this->db->results = [ 0 ];
        LastSeen::set( 7, 1000 );
        $this->assertSame( [ [ 7, LastSeen::KEY, 1000 ] ], $GLOBALS['yaymatik_test_user_meta_added'] );
    }

    /**
     * A visit by user 7 whose last seen is `$ago` seconds back (null: none), signed in with session `tok`.
     *
     * @param int|null $ago
     */
    private function visit_after( ?int $ago ): void {
        $GLOBALS['yaymatik_test_users'][7]           = (object) [ 'meta' => null === $ago ? [] : [ LastSeen::KEY => (string) ( time() - $ago ) ] ];
        $GLOBALS['yaymatik_test_sessions'][7]['tok'] = [ 'login' => 1 ];
        $GLOBALS['yaymatik_test_session_token']      = 'tok';
        $this->signals->visit( 7 );
    }

    public function test_a_visit_after_12_hours_is_a_return_for_the_request_that_moved_last_seen(): void {
        $this->db->results = [ 1 ];
        $this->visit_after( SessionSignals::RETURN_AFTER + 60 );
        $this->assertSame( 'returned', $this->events[0][0]['how'] ?? null, 'T12' );
        $this->assertSame( $this->events[0], $this->events[1] );
        $this->assertStringEndsWith( 'CAST( meta_value AS UNSIGNED ) < ' . ( $this->events[0][0]['time'] - SessionSignals::RETURN_AFTER ), $this->db->last_sql() );

        $this->events      = [ [], [] ];
        $this->db->results = [ 0 ];
        $this->visit_after( SessionSignals::RETURN_AFTER + 60 );
        $this->assertSame( [], $this->events[0], 'N21: the parallel request lost the write' );
    }

    public function test_a_visit_within_12_hours_only_refreshes_last_seen(): void {
        $this->db->results = [ 1 ];
        $this->visit_after( SessionSignals::RETURN_AFTER - 60 );
        $this->assertSame( [], $this->events[0], 'N6' );
        $this->assertStringContainsString( '< ' . ( time() - SessionSignals::REFRESH ), $this->db->last_sql(), 'at most every 15 minutes' );
    }

    public function test_a_first_visit_writes_last_seen_without_an_event(): void {
        $this->db->results = [ 0 ];
        $this->visit_after( null );
        $this->assertSame( [], $this->events[0], 'N8' );
        $this->assertSame( 7, $GLOBALS['yaymatik_test_user_meta_added'][0][0] ?? null );
    }

    public function test_no_return_while_the_sign_in_is_pending_or_for_a_session_of_this_request(): void {
        $GLOBALS['yaymatik_test_users'][7]           = (object) [ 'meta' => [ LastSeen::KEY => '1' ] ];
        $GLOBALS['yaymatik_test_sessions'][7]['tok'] = $this->signals->mark( [], 7 );
        $GLOBALS['yaymatik_test_session_token']      = 'tok';
        $this->signals->visit( 7 );
        $this->signals->created( '', 0, 0, 7, 'logged_in', 'tok' );
        $GLOBALS['yaymatik_test_sessions'][7]['tok'] = [];
        $this->signals->visit( 7 );

        $this->assertSame( [], $this->events[0] );
        $this->assertSame( [], $this->db->calls );
    }
}
