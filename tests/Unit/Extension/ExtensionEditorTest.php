<?php
namespace YayMatik\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Extension\Declaration;
use YayMatik\Workflow\Extension\Diagnostics;
use YayMatik\Workflow\Extension\ExtensionAction;
use YayMatik\Workflow\Extension\ExtensionModule;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** An action's iframe editor: declared field and button, a URL checked each time it's built (docs/extension-api.md §5.6). */
final class ExtensionEditorTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_options'] = [];
    }

    /**
     * @param array $editor More `editor` keys.
     */
    private static function action( array $editor ): ?ExtensionAction {
        $args = Declaration::action(
            new ExtensionModule( 'acme', 'Acme', '', '', null, fn() => true ),
            'acme_mail',
            [
                'label'       => 'Mail',
                'description' => 'Sends.',
                'settings'    => [
                    'design_id' => [
                        'type'    => 'select',
                        'label'   => 'Design',
                        'options' => [ '7' => 'Spring' ],
                    ],
                    'note'      => [
                        'type'  => 'textarea',
                        'label' => 'Note',
                    ],
                ],
                'handle'      => fn( array $config, $step ) => $step->done(),
                'editor'      => $editor + [
                    'field'  => 'design_id',
                    'button' => 'Customize design',
                    'url'    => fn( array $context ) => admin_url( 'admin.php?page=acme&design=' . $context['value'] ),
                ],
            ]
        );
        return $args ? new ExtensionAction( 'acme', 'acme_mail', $args ) : null;
    }

    public function test_the_catalog_names_the_field_and_the_button(): void {
        $this->assertSame(
            [
                'field'  => 'design_id',
                'button' => 'Customize design',
            ],
            self::action( [] )->client_meta()['editor']
        );
    }

    public function test_the_field_must_be_a_declared_text_or_select_field(): void {
        $this->assertNull( self::action( [ 'field' => 'note' ] ), 'a textarea' );
        $this->assertNull( self::action( [ 'field' => 'nope' ] ) );
        $this->assertNull( self::action( [ 'button' => '' ] ) );
        $this->assertNull( self::action( [ 'url' => 'https://evil.test/' ] ), 'a callable, not a URL' );
    }

    public function test_a_same_origin_url_is_built_with_the_context(): void {
        $seen    = null;
        $action  = self::action(
            [
                'url' => function ( array $context ) use ( &$seen ) {
                    $seen = $context;
                    return 'HTTPS://Shop.test:443/wp-admin/admin.php?page=acme&design=' . $context['value'];
                },
            ]
        );
        $context = [
            'value'       => '7',
            'workflow_id' => 3,
            'node_id'     => 'email',
            'step'        => 'acme_mail',
            'config'      => [ 'design_id' => '7' ],
        ];

        $this->assertSame( 'HTTPS://Shop.test:443/wp-admin/admin.php?page=acme&design=7', $action->editor_url( $context ) );
        $this->assertSame( $context, $seen );
    }

    public function test_another_origin_or_a_throw_is_refused_with_a_diagnostic(): void {
        foreach ( [
            'https://evil.test/wp-admin/',
            'http://shop.test/wp-admin/',
            'https://shop.test:8443/wp-admin/',
            '//shop.test/wp-admin/',
            'javascript:alert(1)',
            null,
        ] as $url ) {
            $GLOBALS['yaymatik_test_options'] = [];
            $refused                          = self::action( [ 'url' => fn() => $url ] )->editor_url( [ 'value' => '' ] );

            $this->assertInstanceOf( \WP_Error::class, $refused, (string) $url );
            $this->assertSame( [ 'The editor URL must be a string on this site\'s admin origin (admin_url()).' ], array_column( Diagnostics::recent( 24 ), 'message' ), (string) $url );
        }

        $GLOBALS['yaymatik_test_options'] = [];
        $thrown                           = self::action(
            [
                'url' => function () {
                    throw new \LogicException( 'No designer.' );
                },
            ]
        )->editor_url( [ 'value' => '' ] );
        $this->assertSame( 'yaymatik_editor_url', $thrown->get_error_code() );
        $this->assertSame( [ 'No designer.' ], array_column( Diagnostics::recent( 24 ), 'message' ) );
    }
}
