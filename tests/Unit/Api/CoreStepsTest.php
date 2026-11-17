<?php
namespace YayMatik\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Data\Operators;
use YayMatik\Workflow\Core\Definition\Catalog;
use YayMatik\Workflow\Core\Steps\StepRegistry;

require_once dirname( __DIR__, 3 ) . '/dev/scripts/lib/extension-core.php';
require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/**
 * Core steps, operators and data keys are public for extension templates (docs/extension-api.md §12):
 * a change to one in the PHP catalog fails here. A deliberate change: `./run.sh meta-fixture`,
 * `./run.sh extension-core`, then review the diff of docs/extension-api-core.md (removing or renaming is a MAJOR change).
 */
final class CoreStepsTest extends TestCase {

    private static function read( string $path ): string {
        return (string) file_get_contents( dirname( __DIR__, 3 ) . '/' . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    }

    public function test_core_steps_and_data_are_unchanged_and_documented(): void {
        $core = yaymatik_extension_core(
            [
                'automation' => [
                    'steps'      => Catalog::steps( StepRegistry::all() ),
                    'data'       => DataRegistry::to_client(),
                    'operators'  => Operators::BY_TYPE,
                    'categories' => Catalog::categories(),
                ],
            ]
        );

        $this->assertSame( json_decode( self::read( 'tests/fixtures/extension-api-core.json' ), true ), $core );
        $this->assertSame( self::read( 'docs/extension-api-core.md' ), yaymatik_extension_core_md( $core ), 'docs/extension-api-core.md is stale' );
    }
}
