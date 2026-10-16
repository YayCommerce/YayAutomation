<?php
namespace YayMatik\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use YayMatik\Engine\Register\ScriptName;

/** The admin script loads every WordPress global the prod bundle reads (vite.config.ts externals). */
final class ScriptDepsTest extends TestCase {

    public function test_every_wordpress_external_is_a_script_dep(): void {
        $config = (string) file_get_contents( __DIR__ . '/../../../apps/admin-page/vite.config.ts' );
        $start  = strpos( $config, 'production:' );
        $this->assertNotFalse( $start, 'No production externals block in vite.config.ts.' );

        preg_match_all( "/'@wordpress\/([a-z-]+)'\s*:/", substr( $config, $start ), $matches );
        $this->assertNotEmpty( $matches[1], 'Found no @wordpress externals in vite.config.ts production block; the parser no longer matches the config.' );

        foreach ( $matches[1] as $package ) {
            $this->assertContains( 'wp-' . $package, ScriptName::ADMIN_DEPS, "@wordpress/{$package} is external in prod, so wp-{$package} must be a dep." );
        }
    }
}
