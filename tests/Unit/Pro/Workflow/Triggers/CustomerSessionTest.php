<?php
namespace YayMatik\Tests\Unit\Pro\Workflow\Triggers;

use PHPUnit\Framework\TestCase;
use YayMatik\Pro\Workflow\Triggers\CustomerLoggedIn;
use YayMatik\Pro\Workflow\Triggers\CustomerSession;
use YayMatik\Pro\Workflow\Triggers\UserLoggedIn;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\DataItems\CustomerData;

/** The login triggers: config, when they match, who counts, what a run keeps. */
final class CustomerSessionTest extends TestCase {

    /** @var array[] Calls to the fake stats: [ user id, email ]. */
    private $asked = [];

    /** @var int Paid orders the fake stats report. */
    private $paid = 1;

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_users'] );
    }

    private function customer_trigger(): CustomerLoggedIn {
        return new CustomerLoggedIn(
            function ( int $user_id, string $email ) {
                $this->asked[] = [ $user_id, $email ];
                if ( $this->paid < 0 ) {
                    throw new \RuntimeException( 'Order query failed' );
                }
                return [
                    'count'     => $this->paid,
                    'spent'     => 10.0,
                    'last_paid' => null,
                ];
            }
        );
    }

    /**
     * A `WP_User` as the triggers and `user_can()` read it.
     *
     * @param string[] $roles
     * @param array    $caps
     */
    private static function user( array $roles, array $caps = [] ): object {
        return (object) [
            'ID'         => 7,
            'roles'      => $roles,
            'allcaps'    => $caps,
            'user_email' => 'jane@example.test',
        ];
    }

    /**
     * The context `matches()` gets, with what `about()` adds.
     *
     * @param CustomerSession $trigger
     * @param object          $user
     * @param string          $how
     */
    private static function context( CustomerSession $trigger, object $user, string $how = 'sign_in' ): array {
        $about = new \ReflectionMethod( $trigger, 'about' );
        $about->setAccessible( true );
        return [
            'user' => $user,
            'how'  => $how,
        ] + $about->invoke( $trigger, $user );
    }

    public function test_config_defaults_and_ranges(): void {
        $user = new UserLoggedIn();
        $this->assertSame(
            [
                'when'  => [ 'sign_in', 'return' ],
                'days'  => 7,
                'roles' => [ 'customer' ],
            ],
            $user->sanitize( [] )
        );
        $this->assertSame( [ 'when', 'days' ], array_keys( $this->customer_trigger()->sanitize( [] ) ) );
        $this->assertSame( 8, $user->sanitize( [ 'days' => 7.6 ] )['days'] );
        $this->assertTrue( $user->validate( $user->sanitize( [] ) ) );
        $this->assertNotTrue( $user->validate( $user->sanitize( [ 'when' => [] ] ) ), 'at least one moment' );
        $this->assertNotTrue( $user->validate( $user->sanitize( [ 'days' => 0 ] ) ) );
        $this->assertNotTrue( $user->validate( $user->sanitize( [ 'days' => 366 ] ) ) );
        $this->assertTrue( $user->validate( $user->sanitize( [ 'days' => 365 ] ) ) );
    }

    public function test_once_per_person_every_n_days(): void {
        $trigger = new UserLoggedIn();
        $this->assertTrue( $trigger->claims_per_workflow() );
        $this->assertSame( 3 * DAY_IN_SECONDS, $trigger->claim_cooldown( [ 'days' => 3 ] ) );
        $this->assertSame( 'user:7', $trigger->subject( [ 'user_id' => 7 ] ) );
        $this->assertSame( 'user:7', $trigger->claim_key( [ 'user_id' => 7 ] ) );
    }

    public function test_each_how_needs_its_when(): void {
        $trigger = new UserLoggedIn();
        $user    = self::user( [ 'customer' ] );
        $config  = [
            'when'  => [ 'sign_in', 'return' ],
            'roles' => [],
        ];
        $this->assertTrue( $trigger->matches( self::context( $trigger, $user, 'sign_in' ), $config ) );
        $this->assertTrue( $trigger->matches( self::context( $trigger, $user, 'returned' ), $config ) );
        $this->assertFalse( $trigger->matches( self::context( $trigger, $user, 'auto' ), $config ), 'auto off (N14)' );
        $this->assertTrue( $trigger->matches( self::context( $trigger, $user, 'auto' ), [ 'when' => [ 'auto' ] ] + $config ) );
        $this->assertFalse( $trigger->matches( self::context( $trigger, $user, 'nope' ), $config ) );
    }

    public function test_user_logged_in_filters_by_role_and_empty_means_all(): void {
        $trigger = new UserLoggedIn();
        $config  = [ 'when' => [ 'sign_in' ] ];
        $this->assertTrue( $trigger->matches( self::context( $trigger, self::user( [ 'customer' ] ) ), $config + [ 'roles' => [ 'customer' ] ] ), 'T4' );
        $this->assertFalse( $trigger->matches( self::context( $trigger, self::user( [ 'subscriber' ] ) ), $config + [ 'roles' => [ 'customer' ] ] ), 'N12' );
        $this->assertTrue( $trigger->matches( self::context( $trigger, self::user( [ 'subscriber' ] ) ), $config + [ 'roles' => [] ] ) );
    }

    public function test_customer_logged_in_needs_a_paid_order(): void {
        $trigger = $this->customer_trigger();
        $config  = [ 'when' => [ 'sign_in' ] ];
        $this->assertTrue( $trigger->matches( self::context( $trigger, self::user( [ 'customer' ] ) ), $config ), 'T1' );
        $this->assertSame( [ [ 7, 'jane@example.test' ] ], $this->asked );

        $this->paid = 0;
        $this->assertFalse( $trigger->matches( self::context( $trigger, self::user( [ 'customer' ] ) ), $config ), 'T4, N13: no paid order' );
    }

    public function test_customer_logged_in_never_counts_staff(): void {
        $trigger = $this->customer_trigger();
        $config  = [ 'when' => [ 'sign_in' ] ];
        $this->assertFalse( $trigger->matches( self::context( $trigger, self::user( [ 'shop_manager' ] ) ), $config ), 'N10' );
        $this->assertSame( [], $this->asked, 'staff: no order query' );
        $this->assertFalse( $trigger->matches( self::context( $trigger, self::user( [ 'wholesale' ], [ 'edit_posts' => true ] ) ), $config ), 'N11: a protected capability' );
        $this->assertTrue( $trigger->matches( self::context( $trigger, self::user( [ 'wholesale' ], [ 'read' => true ] ) ), $config ), 'T19: a custom role without one' );
    }

    public function test_the_role_action_filter_does_not_reach_the_trigger(): void {
        $allow = fn() => true;
        add_filter( 'yaymatik_change_role_allow_all', $allow );
        try {
            $trigger = $this->customer_trigger();
            $this->assertFalse( $trigger->matches( self::context( $trigger, self::user( [ 'administrator' ] ) ), [ 'when' => [ 'sign_in' ] ] ) );
        } finally {
            remove_filter( 'yaymatik_change_role_allow_all', $allow );
        }
    }

    public function test_a_failed_order_query_throws_so_no_run_starts(): void {
        $this->paid = -1;
        $this->expectException( \RuntimeException::class );
        self::context( $this->customer_trigger(), self::user( [ 'customer' ] ) );
    }

    public function test_refs_keep_the_customer_and_the_time(): void {
        $GLOBALS['yaymatik_test_users'] = [ 7 => self::user( [ 'customer' ] ) ];
        $trigger                        = new UserLoggedIn();
        $refs                           = $trigger->refs(
            [
                'user_id' => 7,
                'how'     => 'sign_in',
                'time'    => 1791799200,
            ]
        );
        $this->assertEquals( new Ref( CustomerData::ID, 'user:7' ), $refs[ CustomerData::ID ] );
        $this->assertSame( 1791799200, $refs['time'] );
        $this->assertNull( $trigger->refs( [ 'user_id' => 8 ] ), 'a deleted user' );
        $this->assertSame( [ 'time' ], array_keys( $trigger->facts() ) );
        $this->assertIsInt( $trigger->sample_facts()['time'] );
    }
}
