<?php
namespace YayMatik\Tests\Unit\Modules;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Data\DataRegistry;
use YayMatik\Workflow\ModuleRegister;
use YayMatik\Workflow\Modules\MatikModule;
use YayMatik\Workflow\Modules\ModuleInterface;
use YayMatik\Workflow\Modules\WoocommerceModule;
use YayMatik\Workflow\Steps\Core\StepRegistry;

/**
 * Core's modules, what the register builds from them, and the logos. A logo uses only `LOGO_TAGS`
 * (what `wp_kses()` keeps; the real call is checked on the site) and draws in `currentColor`.
 */
final class ModuleRegisterTest extends TestCase {

    public function logos(): array {
        return [
            WoocommerceModule::ID => [ ( new WoocommerceModule() )->logo() ],
            MatikModule::ID       => [ ( new MatikModule() )->logo() ],
        ];
    }

    public function test_core_modules_are_lite_and_on(): void {
        $this->assertSame( [ WoocommerceModule::ID, MatikModule::ID ], array_keys( ModuleRegister::modules() ) );
        foreach ( ModuleRegister::modules() as $module ) {
            $this->assertSame( ModuleInterface::TIER_LITE, $module->tier() );
            $this->assertTrue( $module->enabled() );
        }
    }

    public function test_registries_read_the_modules(): void {
        $this->assertSame(
            [ 'order_created', 'order_status_changed', 'send_email', 'send_internal_email', 'change_order_status', 'add_order_note', 'update_order_meta', 'generate_coupon', 'condition' ],
            array_keys( StepRegistry::all() )
        );
        $this->assertSame( [ 'order', 'customer', 'site' ], array_keys( DataRegistry::items() ) );
    }

    public function test_each_step_names_its_module(): void {
        foreach ( ModuleRegister::modules() as $id => $module ) {
            foreach ( ModuleRegister::steps( [ $module ] ) as $step ) {
                $this->assertSame( $id, $step->plugin(), $step->id() );
            }
        }
    }

    public function test_first_owner_of_an_id_wins(): void {
        $woo   = new WoocommerceModule();
        $steps = ModuleRegister::steps( [ $woo, $woo ] );

        $this->assertCount( count( $woo->steps() ), $steps );
        $this->assertCount( 2, ModuleRegister::items( [ $woo, $woo ] ) );
    }

    public function test_to_client_keeps_its_keys(): void {
        $plugins = array_column( ModuleRegister::to_client(), null, 'id' );

        foreach ( $plugins as $id => $plugin ) {
            $this->assertSame( [ 'id', 'label', 'description', 'active', 'logo' ], array_keys( $plugin ) );
            $this->assertSame( ModuleRegister::modules()[ $id ]->logo(), $plugin['logo'], 'cleaned logo is the source logo' );
        }
        $this->assertTrue( $plugins[ MatikModule::ID ]['active'], 'always active' );
    }

    /**
     * @dataProvider logos
     *
     * @param string $svg Logo.
     */
    public function test_logo_uses_only_allowed_tags_and_attributes( string $svg ): void {
        $doc = new \DOMDocument();
        $this->assertTrue( $doc->loadXML( $svg ), 'well-formed' );
        $nodes = ( new \DOMXPath( $doc ) )->query( '//*' );
        $this->assertGreaterThan( 1, $nodes->length );
        foreach ( $nodes as $node ) {
            $tag = strtolower( $node->localName );
            $this->assertArrayHasKey( $tag, ModuleRegister::LOGO_TAGS );
            foreach ( $node->attributes as $attr ) {
                $this->assertArrayHasKey( strtolower( $attr->name ), ModuleRegister::LOGO_TAGS[ $tag ], "$tag@{$attr->name}" );
            }
        }
        $this->assertSame( 'svg', $doc->documentElement->localName );
    }

    /**
     * @dataProvider logos
     *
     * @param string $svg Logo.
     */
    public function test_logo_has_no_color_of_its_own( string $svg ): void {
        $this->assertDoesNotMatchRegularExpression( '/#[0-9a-f]{3,8}\b|rgba?\(|hsla?\(|style=|color=/i', $svg );

        $doc = new \DOMDocument();
        $doc->loadXML( $svg );
        foreach ( ( new \DOMXPath( $doc ) )->query( '//@fill | //@stroke' ) as $paint ) {
            $this->assertContains( $paint->value, [ 'none', 'currentColor' ], "{$paint->name}=\"{$paint->value}\"" );
        }
        $this->assertSame( 'currentColor', $doc->documentElement->getAttribute( 'stroke' ) );
    }

    /**
     * @dataProvider logos
     *
     * @param string $svg Logo.
     */
    public function test_clean_gives_viewbox_its_case_back( string $svg ): void {
        $this->assertStringContainsString( ' viewBox="0 0 24 24"', $svg );
        // What wp_kses() returns: attribute names lowercased.
        $this->assertSame( $svg, ModuleRegister::clean( str_replace( ' viewBox=', ' viewbox=', $svg ) ) );
    }

    public function test_steps_never_check_the_tier(): void {
        $files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/includes/Workflow/Steps' ) );
        foreach ( $files as $file ) {
            if ( 'php' !== $file->getExtension() ) {
                continue;
            }
            $code = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading source in a test.
            $this->assertDoesNotMatchRegularExpression( '/\bis_pro\b|TIER_PRO|TIER_LITE|\btier\b|Pro\b/', $code, $file->getFilename() );
        }
    }
}
