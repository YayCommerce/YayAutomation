<?php
namespace YayAutomation\Tests\Unit\Extend;

use PHPUnit\Framework\TestCase;
use YayAutomation\Workflow\Extend\Plugins;

/**
 * Plugin entries and logos. A logo uses only `LOGO_TAGS` (what `wp_kses()` keeps; the real call is
 * checked on the site) and draws in `currentColor`, with no color of its own.
 */
final class PluginsTest extends TestCase {

    public function logos(): array {
        return array_map( fn( string $svg ) => [ $svg ], Plugins::logos() );
    }

    public function test_every_plugin_has_a_logo(): void {
        $this->assertSame( [ Plugins::WOOCOMMERCE, Plugins::YAYAUTOMATION ], array_keys( Plugins::logos() ) );
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
            $this->assertArrayHasKey( $tag, Plugins::LOGO_TAGS );
            foreach ( $node->attributes as $attr ) {
                $this->assertArrayHasKey( strtolower( $attr->name ), Plugins::LOGO_TAGS[ $tag ], "$tag@{$attr->name}" );
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

    public function test_to_client_keeps_its_keys_and_adds_the_logo(): void {
        $plugins = array_column( Plugins::to_client(), null, 'id' );

        foreach ( $plugins as $plugin ) {
            $this->assertSame( [ 'id', 'label', 'description', 'active', 'logo' ], array_keys( $plugin ) );
            $this->assertSame( Plugins::logos()[ $plugin['id'] ], $plugin['logo'], 'cleaned logo is the source logo' );
        }
        $this->assertTrue( $plugins[ Plugins::YAYAUTOMATION ]['active'], 'always active' );
    }

    /**
     * @dataProvider logos
     *
     * @param string $svg Logo.
     */
    public function test_clean_gives_viewbox_its_case_back( string $svg ): void {
        $this->assertStringContainsString( ' viewBox="0 0 24 24"', $svg );
        // What wp_kses() returns: attribute names lowercased.
        $this->assertSame( $svg, Plugins::clean( str_replace( ' viewBox=', ' viewbox=', $svg ) ) );
    }
}
