<?php
namespace YayMatik\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeMailer;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Email\EmailDelivery;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;
use YayMatik\Workflow\Extension\Declaration;
use YayMatik\Workflow\Extension\Diagnostics;
use YayMatik\Workflow\Extension\ExtensionEmailAction;
use YayMatik\Workflow\Extension\ExtensionModule;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__, 2 ) . '/Support/fake-wc.php';

/** An email action: the plugin builds, core renders merge tags, adds the footer when missing, and sends. */
final class ExtensionEmailTest extends TestCase {

    /** @var array What `build` was given: [ config, run ]. */
    private $built = [];

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_options'] = [];
        FakeMailer::$current              = new FakeMailer();
    }

    /**
     * @param callable|array $build fn( $config, $step ): array, or the array it returns.
     * @param array          $email More `email` keys.
     * @param array          $extra More declaration keys.
     */
    private function action( $build, array $email = [], array $extra = [] ): ?ExtensionEmailAction {
        $args = Declaration::action(
            new ExtensionModule( 'acme', 'Acme', '', '', null, fn() => true ),
            'acme_mail',
            $extra + [
                'label'       => 'Mail',
                'description' => 'Sends.',
                'settings'    => [
                    'to' => [
                        'type'      => 'text',
                        'label'     => 'To',
                        'variables' => true,
                    ],
                ],
                'email'       => $email + [
                    'recipient' => 'customer',
                    'build'     => function ( array $config, $step ) use ( $build ) {
                        $this->built = [ $config, $step ];
                        return is_callable( $build ) ? $build( $config, $step ) : $build;
                    },
                ],
            ]
        );
        if ( null === $args ) {
            return null;
        }
        return new ExtensionEmailAction( 'acme', 'acme_mail', $args, new EmailDelivery( new UnsubscribeLink( 'secret' ), fn( string $email ) => 'gone@example.test' === $email ) );
    }

    /**
     * @param string $email
     */
    private static function scope( string $email = 'ann@example.test' ): Scope {
        return new Scope(
            [
                'trigger' => [
                    'customer' => [
                        'email'      => $email,
                        'first_name' => 'Ann <b> & "Co"',
                        'site'       => 'https://shop.test/a b',
                    ],
                ],
            ]
        );
    }

    private static function url(): string {
        return ( new UnsubscribeLink( 'secret' ) )->url( 'ann@example.test' );
    }

    public function test_a_full_document_comes_out_byte_identical_except_its_merge_tags(): void {
        $document = "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><style>body{font:14px sans-serif}td > p{margin:0}</style></head>\n"
            . '<body><table role="presentation" width="100%"><tr><td><table><tr><td class="x" data-a=\'1\'>'
            . "Line one\n\nLine two &amp; more</td></tr></table></td></tr></table>"
            . '<p>Hi {{ customer.first_name }}</p>'
            . '<a href="{{ customer.site }}">Shop</a><a href="https://shop.test/?n={{ customer.first_name }}">Me</a>'
            . '<img alt="{{ customer.first_name }}" src="x.png">'
            . '<!-- {{ customer.first_name }} --><p><a href="UNSUB">Unsubscribe</a></p></body></html>';
        $action   = $this->action(
            fn( array $config, $step ) => [
                'subject' => 'Hi {{ customer.first_name }}',
                'html'    => str_replace( 'UNSUB', esc_url( $step->unsubscribe_url() ), $document ),
            ]
        );

        $email = $action->compose_email( [ 'to' => '' ], self::scope(), self::url() );

        $name = 'Ann <b> & "Co"';
        $this->assertSame(
            str_replace(
                [ 'Hi {{ customer.first_name }}</p>', '{{ customer.site }}', '?n={{ customer.first_name }}', 'alt="{{ customer.first_name }}"', '<!-- {{ customer.first_name }} -->', 'UNSUB' ],
                [ 'Hi ' . esc_html( $name ) . '</p>', esc_url( 'https://shop.test/a b' ), '?n=' . rawurlencode( $name ), 'alt="' . esc_attr( $name ) . '"', '<!-- ' . esc_html( $name ) . ' -->', esc_url( self::url() ) ],
                $document
            ),
            $email['body'],
            'no wpautop, no kses, nothing added: the plugin placed the unsubscribe link'
        );
        $this->assertSame( 'Hi Ann <b> & "Co"', $email['subject'], 'subject as plain text' );
        $this->assertTrue( $email['raw'], 'sent as built' );
        $this->assertSame( [ 'to' => '' ], $this->built[0], 'build gets the step config' );
    }

    public function test_the_footer_goes_before_body_end_once_when_the_link_is_missing(): void {
        $action = $this->action(
            [
                'subject' => 'S',
                'html'    => '<html><body><p>Hi</p></body></html>',
            ]
        );

        $email = $action->compose_email( [], self::scope(), self::url() );

        $this->assertSame( 1, substr_count( $email['body'], 'Unsubscribe' ) );
        $this->assertStringEndsWith( '<a href="' . esc_url( self::url() ) . '">Unsubscribe</a></p></body></html>', $email['body'] );
        $this->assertSame(
            "Content-Type: text/html\r\nList-Unsubscribe: <" . self::url() . ">\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n",
            $email['headers']
        );
    }

    public function test_wrap_puts_the_body_in_woocommerces_template_with_the_footer_after_it(): void {
        $action = $this->action(
            [
                'subject' => 'Thanks {{ customer.first_name }}',
                'html'    => '<p>Body</p>',
                'wrap'    => true,
            ]
        );

        $email = $action->compose_email( [], self::scope(), self::url() );

        $this->assertStringStartsWith( '<h1>Thanks Ann &lt;b&gt; &amp; &quot;Co&quot;</h1><p>Body</p><p style=', $email['body'], 'FakeMailer wraps with the subject as heading' );
        $this->assertFalse( $email['raw'] );
    }

    public function test_preview_and_send_test_get_an_inert_link(): void {
        $action = $this->action(
            fn( array $config, $step ) => [
                'subject' => 'S',
                'html'    => '<body><a href="' . esc_url( $step->unsubscribe_url() ) . '">Out</a></body>',
            ]
        );

        $preview = $action->compose_email( [], self::scope(), null );
        $step    = $this->built[1];
        $action->compose_email( [], self::scope(), null, true );

        $this->assertSame( '<body><a href="#">Out</a></body>', $preview['body'], 'it asked for the link: no footer' );
        $this->assertSame( "Content-Type: text/html\r\n", $preview['headers'] );
        $this->assertTrue( $step->is_preview() );
        $this->assertFalse( $step->is_test() );
        $this->assertTrue( $this->built[1]->is_test() );
    }

    public function test_a_design_with_other_links_to_hash_still_gets_the_footer_in_preview(): void {
        $action = $this->action(
            [
                'subject' => 'S',
                'html'    => '<body><a href="#">Follow us</a></body>',
            ]
        );

        $preview = $action->compose_email( [], self::scope(), null );
        $sent    = $action->compose_email( [], self::scope(), self::url() );

        $this->assertStringEndsWith( '<a href="#">Unsubscribe</a></p></body>', $preview['body'], 'as the real send will' );
        $this->assertStringEndsWith( '<a href="' . esc_url( self::url() ) . '">Unsubscribe</a></p></body>', $sent['body'] );
    }

    public function test_a_staff_email_has_no_footer_or_unsubscribe_headers(): void {
        $action = $this->action(
            [
                'subject' => 'S',
                'html'    => '<body>Hi</body>',
            ],
            [
                'audience'  => 'staff',
                'recipient' => 'to',
            ]
        );

        $scope = self::scope();
        $scope->set_current_step( 5, 'mail', 31 );
        $result = $action->run( $scope, [ 'to' => 'Team@Example.test' ] );

        $this->assertTrue( $result->is_ok() );
        $this->assertSame( [ 'to' => 'team@example.test' ], $result->outputs() );
        $this->assertSame( [ 'team@example.test', 'S', '<body>Hi</body>' ], FakeMailer::$current->sent[0] );
        $this->assertSame( "Content-Type: text/html\r\nX-YayMatik-Mark: 31\r\n", FakeMailer::$current->headers[0], 'the mark on a staff email too' );
        $this->assertSame( '', $this->built[1]->unsubscribe_url() );
        $this->assertFalse( $this->built[1]->is_preview(), 'a real send' );
        $this->assertSame( [], $action->requires(), 'a typed recipient needs no customer' );
    }

    public function test_a_run_sends_to_the_customer_and_skips_one_who_unsubscribed(): void {
        $action = $this->action(
            [
                'subject' => 'S',
                'html'    => '<body>Hi</body>',
            ]
        );

        $sent    = $action->run( self::scope(), [] );
        $skipped = $action->run( self::scope( 'gone@example.test' ), [] );

        $this->assertSame( [ 'to' => 'ann@example.test' ], $sent->outputs() );
        $this->assertFalse( $this->built[1]->is_preview() );
        $this->assertSame( self::url(), $this->built[1]->unsubscribe_url() );
        $this->assertSame( 'The customer unsubscribed from these emails.', $skipped->message() );
        $this->assertCount( 1, FakeMailer::$current->sent );
    }

    public function test_a_build_that_throws_or_returns_no_html_fails_with_a_diagnostic(): void {
        $throws = $this->action(
            function () {
                throw new \LogicException( 'Template 7 is gone.' );
            }
        );
        $junk   = $this->action( 'nope' );

        $reasons = [];
        foreach ( [ $throws, $junk ] as $action ) {
            foreach ( [ null, self::url() ] as $unsubscribe ) {
                try {
                    $action->compose_email( [], self::scope(), $unsubscribe );
                    $this->fail( 'no email' );
                } catch ( \RuntimeException $e ) {
                    $reasons[] = $e->getMessage();
                }
            }
        }

        $this->assertSame( [ 'Template 7 is gone.', 'Couldn’t build the email.', 'Couldn’t build the email.', 'Couldn’t build the email.' ], $reasons, 'Preview shows the plugin’s error' );
        $this->assertSame( [ 'Template 7 is gone.' ], array_column( Diagnostics::recent( 24 ), 'message' ), 'later ones within 10 minutes are counted, not written' );
        $GLOBALS['yaymatik_test_options'] = [];
        try {
            $junk->compose_email( [], self::scope(), null );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( [ 'build must return [ subject, html, wrap ].' ], array_column( Diagnostics::recent( 24 ), 'message' ) );
        }
    }

    public function test_the_declaration_rules(): void {
        $this->assertNull( $this->action( [], [], [ 'handle' => fn() => null ] ), 'core runs an email action' );
        $this->assertNull( $this->action( [], [ 'recipient' => 'email' ] ), 'recipient: customer or a field' );
        $this->assertNull( $this->action( [], [ 'audience' => 'everyone' ] ) );
        $action = $this->action( [] );
        $this->assertSame( 'customer', $action->email_audience() );
        $this->assertSame( [ 'customer' ], $action->requires(), '{{ customer.email }} needs a customer' );
        $this->assertSame( [ 'to' ], array_keys( $action->outputs() ) );
        $this->assertSame( 'Sent to {{ output.to }}', $action->client_meta()['result'] );
        $this->assertSame( 'Mailed', $this->action( [], [], [ 'result' => 'Mailed' ] )->client_meta()['result'] );
    }
}
