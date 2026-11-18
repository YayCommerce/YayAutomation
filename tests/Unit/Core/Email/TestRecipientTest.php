<?php
namespace YayMatik\Tests\Unit\Core\Email;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Email\TestRecipient;

/** The test address: saved per user, else the profile email; only a valid one is saved. */
final class TestRecipientTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_users'] = [
            7 => (object) [
                'user_email' => 'admin@example.test',
                'meta'       => [],
            ],
        ];
    }

    protected function tearDown(): void {
        unset( $GLOBALS['yaymatik_test_users'] );
    }

    public function test_the_profile_email_until_one_is_saved(): void {
        $this->assertSame( 'admin@example.test', TestRecipient::of( 7 ) );
        $this->assertSame( 'qa@example.test', TestRecipient::save( 7, ' qa@example.test ' ) );
        $this->assertSame( 'qa@example.test', $GLOBALS['yaymatik_test_users'][7]->meta[ TestRecipient::META ] );
        $this->assertSame( 'qa@example.test', TestRecipient::of( 7 ) );
    }

    public function test_an_invalid_address_is_refused_and_not_saved(): void {
        foreach ( [ 'not-an-email', '', null, [ 'a@b.test' ] ] as $email ) {
            $error = TestRecipient::save( 7, $email );
            $this->assertInstanceOf( \WP_Error::class, $error );
            $this->assertSame( [ 'yaymatik_invalid_email', 400 ], [ $error->get_error_code(), $error->get_error_data()['status'] ] );
        }
        $this->assertSame( [], $GLOBALS['yaymatik_test_users'][7]->meta );
    }
}
