<?php
namespace YayMatik\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/dev/scripts/lib/extension-stubs.php';

/** The PHPStan stubs extensions check against match includes/Api: after a change there, `./run.sh extension-stubs`. */
final class StubsTest extends TestCase {

    public function test_the_stubs_match_the_api(): void {
        $root = dirname( __DIR__, 3 );
        $this->assertSame(
            (string) file_get_contents( $root . '/stubs/yaymatik-extension-api.php' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            yaymatik_extension_stubs( $root . '/includes/Api' ),
            'includes/Api changed: run ./run.sh extension-stubs.'
        );
    }
}
