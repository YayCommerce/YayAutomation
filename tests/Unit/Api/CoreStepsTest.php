<?php
namespace YayMatik\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Data\Operators;
use YayMatik\Workflow\Core\Definition\Catalog;
use YayMatik\Workflow\Core\Definition\ProCatalog;
use YayMatik\Workflow\Core\Steps\StepRegistry;

require_once dirname( __DIR__, 3 ) . '/dev/scripts/lib/extension-core.php';
require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/**
 * Core steps, operators and data keys are public for extension templates (docs/extension-api.md §12):
 * a change to one in the PHP catalog fails here. A deliberate change: `./run.sh meta-fixture`,
 * `./run.sh extension-core`, then review the diff of docs/extension-api-core.md (removing or renaming is a MAJOR change).
 * The free edition has the `lite` tier only.
 */
final class CoreStepsTest extends TestCase {

    private static function read( string $path ): string {
        return (string) file_get_contents( dirname( __DIR__, 3 ) . '/' . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    }

    public function test_core_steps_and_data_are_unchanged_and_documented(): void {
        $core   = yaymatik_extension_core(
            [
                'automation' => [
                    'steps'      => Catalog::steps( StepRegistry::all() ),
                    'data'       => DataRegistry::to_client(),
                    'operators'  => Operators::BY_TYPE,
                    'categories' => Catalog::categories(),
                ],
            ],
            ProCatalog::STEPS,
            ProCatalog::FIELDS
        );
        $listed = json_decode( self::read( 'tests/fixtures/extension-api-core.json' ), true );

        if ( YAYMATIK_TEST_LITE ) {
            $listed['steps'] = array_filter( $listed['steps'], fn( array $step ) => 'lite' === $step['tier'] );
            $listed['data']  = array_map( fn( array $keys ) => array_filter( $keys, fn( string $tier ) => 'lite' === $tier ), $listed['data'] );
            $this->assertSame( $listed, $core );
            return;
        }
        $this->assertSame( $listed, $core );
        $this->assertSame( self::read( 'docs/extension-api-core.md' ), yaymatik_extension_core_md( $core ), 'docs/extension-api-core.md is stale' );
    }
}
