<?php
namespace YayMatik\Tests\Unit\Pro\Workflow\Actions;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeOrder;
use YayMatik\Tests\Support\FakeUser;
use YayMatik\Pro\Workflow\Actions\ChangeCustomerRole;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Steps\StepResult;

require_once dirname( __DIR__, 4 ) . '/Support/woocommerce-stubs.php';

/** Change customer role: what it gives, what it never touches, and its outputs. */
final class ChangeCustomerRoleTest extends TestCase {

    /** @var callable|null */
    private $filter = null;

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_roles'] = new \WP_Roles(
            [
                'administrator' => [
                    'name'         => 'Administrator',
                    'capabilities' => [ 'manage_options' => true ],
                ],
                'shop_manager'  => [
                    'name'         => 'Shop manager',
                    'capabilities' => [ 'read' => true ],
                ],
                'editor'        => [
                    'name'         => 'Editor',
                    'capabilities' => [ 'unfiltered_html' => true ],
                ],
                'author'        => [
                    'name'         => 'Author',
                    'capabilities' => [
                        'read'          => true,
                        'publish_posts' => true,
                        'upload_files'  => true,
                    ],
                ],
                'contributor'   => [
                    'name'         => 'Contributor',
                    'capabilities' => [
                        'read'       => true,
                        'edit_posts' => true,
                    ],
                ],
                'helper'        => [
                    'name'         => 'Helper',
                    'capabilities' => [
                        'read'       => true,
                        'edit_users' => true,
                    ],
                ],
                'was_staff'     => [
                    'name'         => 'Former staff',
                    'capabilities' => [
                        'read'       => true,
                        'edit_users' => false,
                    ],
                ],
                'customer'      => [
                    'name'         => 'Customer',
                    'capabilities' => [ 'read' => true ],
                ],
                'wholesale'     => [
                    'name'         => 'Wholesale customer',
                    'capabilities' => [ 'read' => true ],
                ],
            ]
        );
    }

    protected function tearDown(): void {
        if ( $this->filter ) {
            remove_filter( 'yaymatik_change_role_allow_all', $this->filter );
        }
        unset( $GLOBALS['yaymatik_test_roles'], $GLOBALS['yaymatik_test_users'], $GLOBALS['yaymatik_test_super_admins'] );
    }

    private function allow_all(): void {
        $this->filter = fn() => true;
        add_filter( 'yaymatik_change_role_allow_all', $this->filter );
    }

    /**
     * A run whose customer is user 7.
     *
     * @param string[] $roles
     * @param array    $caps
     */
    private function scope( array $roles, array $caps = [] ): Scope {
        $GLOBALS['yaymatik_test_users'] = [ 7 => new FakeUser( 7, $roles, $caps ) ];
        $order                          = new FakeOrder( [ 'customer_id' => 7 ] );
        return new Scope(
            [ 'trigger' => [ 'customer' => new Ref( 'customer', 'order:1' ) ] ],
            new RefResolver( [ 'customer' => fn() => $order ] )
        );
    }

    private function user(): FakeUser {
        return $GLOBALS['yaymatik_test_users'][7];
    }

    /**
     * @param string   $mode
     * @param string   $role
     * @param string[] $roles
     * @param array    $caps
     */
    private function run_role( string $mode, string $role, array $roles, array $caps = [] ): StepResult {
        $step   = new ChangeCustomerRole();
        $config = $step->sanitize(
            [
                'mode' => $mode,
                'role' => $role,
            ]
        );
        return $step->run( $this->scope( $roles, $caps ), $config );
    }

    public function test_lists_only_roles_without_a_protected_capability(): void {
        $this->assertSame(
            [
                'was_staff' => 'Former staff',
                'customer'  => 'Customer',
                'wholesale' => 'Wholesale customer',
            ],
            ChangeCustomerRole::assignable_roles()
        );
        foreach ( [ 'author', 'editor', 'contributor' ] as $role ) {
            $this->assertArrayNotHasKey( $role, ChangeCustomerRole::assignable_roles(), $role );
        }
        $this->allow_all();
        $this->assertCount( 9, ChangeCustomerRole::assignable_roles(), 'the filter lists every role' );
    }

    public function test_a_role_outside_the_list_is_an_issue_at_save(): void {
        $step = new ChangeCustomerRole();
        foreach ( [ 'administrator', 'shop_manager', 'helper', 'author', 'contributor', 'gone' ] as $role ) {
            $this->assertInstanceOf( \WP_Error::class, $step->validate( $step->sanitize( [ 'role' => $role ] ) ), $role );
        }
        $this->assertTrue( $step->validate( $step->sanitize( [ 'role' => 'wholesale' ] ) ) );
        $this->assertSame( 'add', $step->sanitize( [ 'mode' => 'promote' ] )['mode'] );
    }

    public function test_add_keeps_the_other_roles(): void {
        $result = $this->run_role( 'add', 'wholesale', [ 'customer' ] );
        $this->assertTrue( $result->is_ok() );
        $this->assertSame( [ 'customer', 'wholesale' ], $this->user()->roles );
        $this->assertSame(
            [
                'from' => 'Customer',
                'to'   => 'Customer, Wholesale customer',
            ],
            $result->outputs()
        );
    }

    public function test_a_role_already_there_writes_nothing(): void {
        $result = $this->run_role( 'add', 'customer', [ 'customer' ] );
        $this->assertTrue( $result->is_ok() );
        $this->assertSame( [], $this->user()->changes );
        $this->assertSame( $result->outputs()['from'], $result->outputs()['to'] );
        $this->run_role( 'remove', 'wholesale', [ 'customer', 'was_staff' ] );
        $this->assertSame( [], $this->user()->changes, 'removing a role they don\'t have' );
    }

    public function test_remove_never_takes_the_last_role(): void {
        $result = $this->run_role( 'remove', 'customer', [ 'customer' ] );
        $this->assertTrue( $result->is_skipped() );
        $this->assertSame( 'Customer is their only role.', $result->message() );
        $this->assertSame( [ 'customer' ], $this->user()->roles );

        $this->assertTrue( $this->run_role( 'remove', 'customer', [ 'customer', 'wholesale' ] )->is_ok() );
        $this->assertSame( [ 'wholesale' ], $this->user()->roles );
    }

    public function test_replace_adds_first_then_drops_the_rest(): void {
        $result = $this->run_role( 'replace', 'wholesale', [ 'customer', 'was_staff' ] );
        $this->assertTrue( $result->is_ok() );
        $this->assertSame( [ '+wholesale', '-customer', '-was_staff' ], $this->user()->changes );
        $this->assertSame( [ 'wholesale' ], $this->user()->roles );
        $this->assertSame( 'Wholesale customer', $result->outputs()['to'] );
    }

    /**
     * @dataProvider protected_users
     * @param string[] $roles
     * @param array    $caps
     */
    public function test_never_changes_store_staff( array $roles, array $caps ): void {
        $result = $this->run_role( 'replace', 'wholesale', $roles, $caps );
        $this->assertTrue( $result->is_skipped() );
        $this->assertSame( 'This customer has a protected role.', $result->message() );
        $this->assertSame( [], $this->user()->changes );
    }

    public function protected_users(): array {
        return [
            'administrator'        => [ [ 'administrator' ], [ 'manage_options' => true ] ],
            'shop manager'         => [ [ 'shop_manager' ], [] ],
            'a custom role'        => [ [ 'helper' ], [ 'edit_users' => true ] ],
            'a cap given directly' => [ [ 'customer' ], [ 'edit_shop_orders' => true ] ],
        ];
    }

    public function test_never_changes_a_super_admin(): void {
        $scope                                 = $this->scope( [ 'customer' ] );
        $GLOBALS['yaymatik_test_super_admins'] = [ 7 => true ];
        $step                                  = new ChangeCustomerRole();
        $this->assertTrue( $step->run( $scope, $step->sanitize( [ 'role' => 'wholesale' ] ) )->is_skipped() );
    }

    public function test_a_role_made_protected_or_deleted_after_the_save_fails(): void {
        $GLOBALS['yaymatik_test_roles']->roles['wholesale']['capabilities']['manage_woocommerce'] = true;
        $step   = new ChangeCustomerRole();
        $config = [
            'mode' => 'add',
            'role' => 'wholesale',
        ];
        $result = $step->run( $this->scope( [ 'customer' ] ), $config );
        $this->assertTrue( $result->is_failed() );
        $this->assertSame( [], $this->user()->changes );

        $removed = $step->run(
            $this->scope( [ 'customer', 'was_staff' ] ),
            [
                'mode' => 'remove',
                'role' => 'wholesale',
            ]
        );
        $this->assertTrue( $removed->is_ok(), 'removing it is harmless' );

        unset( $GLOBALS['yaymatik_test_roles']->roles['wholesale'] );
        $result = $step->run( $this->scope( [ 'customer' ] ), $config );
        $this->assertTrue( $result->is_failed() );
        $this->assertSame( 'The role wholesale no longer exists.', $result->message() );
        $removed = $step->run(
            $this->scope( [ 'customer' ] ),
            [
                'mode' => 'remove',
                'role' => 'wholesale',
            ]
        );
        $this->assertTrue( $removed->is_ok() );
        $this->assertSame( [], $this->user()->changes );
    }

    public function test_the_filter_lifts_the_protection_but_not_the_last_role_rule(): void {
        $this->allow_all();
        $this->assertTrue( $this->run_role( 'add', 'helper', [ 'shop_manager' ] )->is_ok() );
        $this->assertSame( [ 'shop_manager', 'helper' ], $this->user()->roles );
        $this->assertTrue( $this->run_role( 'remove', 'administrator', [ 'administrator' ], [ 'manage_options' => true ] )->is_skipped() );
        // Replace still keeps the protected roles they hold.
        $this->run_role( 'replace', 'wholesale', [ 'administrator', 'customer' ], [ 'manage_options' => true ] );
        $this->assertSame( [ 'administrator', 'wholesale' ], $this->user()->roles );
    }

    public function test_a_guest_or_a_deleted_account_is_skipped(): void {
        $step   = new ChangeCustomerRole();
        $config = $step->sanitize( [ 'role' => 'wholesale' ] );
        $this->assertTrue( $step->run( new Scope(), $config )->is_skipped() );
        $order  = new FakeOrder( [ 'customer_id' => 0 ] );
        $guest  = new Scope(
            [ 'trigger' => [ 'customer' => new Ref( 'customer', 'order:1' ) ] ],
            new RefResolver( [ 'customer' => fn() => $order ] )
        );
        $result = $step->run( $guest, $config );
        $this->assertTrue( $result->is_skipped() );
        $this->assertSame( 'This customer has no account.', $result->message() );
    }
}
