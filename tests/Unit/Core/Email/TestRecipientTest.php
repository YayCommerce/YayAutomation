<?php
namespace YayMatik\Tests\Unit\Core\Email;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Email\TestRecipient;

/** The test address: saved per user, else the profile email; a given recipient must be valid. */
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

    public function test_send_takes_the_recipient_then_the_saved_then_the_profile_address(): void {
        $this->assertSame( 'admin@example.test', TestRecipient::for_send( 7, null ) );
        TestRecipient::save( 7, 'qa@example.test' );
        $this->assertSame( 'qa@example.test', TestRecipient::for_send( 7, null ) );
        $this->assertSame( 'other@example.test', TestRecipient::for_send( 7, 'other@example.test' ) );
        $this->assertInstanceOf( \WP_Error::class, TestRecipient::for_send( 7, 'nope' ) );
    }
}
