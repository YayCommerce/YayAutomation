<?php
namespace YayMatik\Tests\Unit\Engine\API;

use PHPUnit\Framework\TestCase;
use YayMatik\Engine\API\RestAPI;
use YayMatik\Workflow\Core\Email\TestRecipient;

/** The test-email route: who may save, and what it saves. */
final class RestAPITest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_user_id'] = 7;
        $GLOBALS['yaymatik_test_users']   = [
            7 => (object) [
                'user_email' => 'admin@example.test',
                'meta'       => [],
            ],
        ];
    }

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_user_id'], $GLOBALS['yaymatik_test_users'], $GLOBALS['yaymatik_test_routes'], $GLOBALS['yaymatik_test_caps'] );
    }

    public function test_the_route_needs_the_send_test_capability(): void {
        RestAPI::get_instance()->register_routes();
        $route = $GLOBALS['yaymatik_test_routes']['yaymatik/v1/test-email'];
        $this->assertSame( $GLOBALS['yaymatik_test_routes']['yaymatik/v1/workflows/(?P<id>\d+)/send-test']['permission_callback'], $route['permission_callback'] );

        $this->assertFalse( call_user_func( $route['permission_callback'] ) );
        $GLOBALS['yaymatik_test_caps'] = [ 'manage_woocommerce' ];
        $this->assertTrue( call_user_func( $route['permission_callback'] ) );
    }

    public function test_a_valid_address_is_saved_for_the_user(): void {
        $response = RestAPI::get_instance()->save_test_email( new \WP_REST_Request( [ 'email' => 'qa@example.test' ] ) );
        $this->assertSame( [ 'testEmail' => 'qa@example.test' ], $response );
        $this->assertSame( 'qa@example.test', TestRecipient::of( 7 ) );
    }

    public function test_an_invalid_address_gets_a_400(): void {
        $response = RestAPI::get_instance()->save_test_email( new \WP_REST_Request( [ 'email' => 'qa@' ] ) );
        $this->assertInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 400, $response->get_error_data()['status'] );
        $this->assertSame( [], $GLOBALS['yaymatik_test_users'][7]->meta );
    }
}
