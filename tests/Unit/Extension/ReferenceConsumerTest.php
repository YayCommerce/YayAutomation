<?php
namespace YayMatik\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeQueue;
use YayMatik\Tests\Support\FakeRunRepository;
use YayMatik\Tests\Support\FakeUser;
use YayMatik\Tests\Support\FakeWorkflows;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Runtime\Dispatcher;
use YayMatik\Workflow\DataItems\CustomerData;
use YayMatik\Workflow\Extension\Diagnostics;
use YayMatik\Workflow\Extension\Extensions;
use YayMatik\Workflow\Extension\Fire;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__, 2 ) . '/Support/yaywholesale-reference.php';

if ( ! defined( 'YAYWHOLESALE_FILE' ) ) {
    define( 'YAYWHOLESALE_FILE', '/plugins/yaywholesale/yaywholesale.php' );
}

/** The YayWholesale reference consumer registers and runs "approved → Set wholesale role" as written. */
final class ReferenceConsumerTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_options'] = [];
        $GLOBALS['yaymatik_test_users']   = [ 5 => new FakeUser( 5, [ 'customer' ] ) ];
    }

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_users'], $GLOBALS['yaymatik_test_filters']['yaymatik_data_item_customer_fields'] );
    }

    public function test_approved_then_set_wholesale_role(): void {
        $module = Extensions::collect( [], [ new \YayWholesale_Reference_YayMatik(), 'register' ] )[0];
        $steps  = [];
        foreach ( array_merge( $module->triggers(), $module->actions() ) as $step ) {
            $steps[ $step->id() ] = $step;
        }
        $this->assertSame( [ 'yaywholesale_approved', 'yaywholesale_set_role' ], array_keys( $steps ) );
        $this->assertSame( '1.0', $module->requires_api() );

        $runs                    = new FakeRunRepository();
        $workflows               = new FakeWorkflows();
        $trigger                 = $steps['yaywholesale_approved'];
        $action                  = $steps['yaywholesale_set_role'];
        $workflows->workflows[1] = [
            'id'     => '1',
            'status' => 'active',
            'graph'  => [
                'nodes' => [
                    [
                        'id'   => 't',
                        'kind' => 'trigger',
                        'data' => [
                            'block'  => 'yaywholesale_approved',
                            'config' => $trigger->sanitize( [] ),
                        ],
                    ],
                ],
                'edges' => [],
            ],
        ];
        $workflows->versions[1]  = 11;
        $dispatcher              = new Dispatcher( $runs, new FakeQueue(), $workflows, function () {}, fn() => null, fn() => [] );
        $fire                    = new Fire( $workflows, $dispatcher, fn( string $id ) => $steps[ $id ] ?? null );

        $fire->handle(
            'yaywholesale_approved',
            [
                'user_id' => 5,
                'role'    => 'gold',
            ]
        );
        $dispatcher->flush();
        $fire->handle(
            'yaywholesale_approved',
            [
                'user_id' => 5,
                'role'    => 'gold',
            ]
        );
        $dispatcher->flush();

        $this->assertCount( 1, $runs->runs, 'once_per subject' );
        $this->assertSame( 'user:5', $runs->runs[1]['subject'] );

        $config = $action->sanitize( [ 'role' => 'gold' ] );
        $this->assertTrue( $action->validate( $config ) );
        $scope  = Scope::from_array(
            $runs->runs[1]['scope'],
            new RefResolver( [ CustomerData::ID => fn( string $id ) => 'user:5' === $id ? new \WC_Customer( [ 'id' => 5 ] ) : null ] ),
            [ CustomerData::ID => new CustomerData() ]
        );
        $result = $action->run( $scope, $config );

        $this->assertSame( [ 'ok', [ 'role' => 'gold' ] ], [ $result->status(), $result->outputs() ] );
        $this->assertSame( 'gold', $GLOBALS['yaymatik_test_users'][5]->meta['_yaywholesale_role'] );
        $this->assertSame( 'gold', $scope->get( 'trigger.role' ), 'the fact is kept' );
        $this->assertSame( [], Diagnostics::recent( 24 ), 'nothing in the payload was dropped' );

        // The data field reads the role through a CustomerView: a condition on it and a merge tag.
        $field = apply_filters( 'yaymatik_data_item_customer_fields', [] )['yaywholesale_role'];
        $this->assertSame( 'gold', ( $field['value'] )( new \WC_Customer( [ 'id' => 5 ] ) ) );
        $this->assertSame( 'Gold', DataItem::display( $field + [ 'display' => null ], new \WC_Customer( [ 'id' => 5 ] ), false ) );
    }
}
