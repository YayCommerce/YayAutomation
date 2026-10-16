<?php
namespace YayMatik\Tests\Unit\Extend;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Extend\Modules;
use YayMatik\Workflow\Steps\Core\Step;
use YayMatik\Workflow\Steps\Core\StepRegistry;

/** The modules manifest and its on/off switch: an off module's steps are unknown. */
final class ModulesTest extends TestCase {

    private const CORE_STEPS = [
        'order_created',
        'order_status_changed',
        'condition',
        'send_internal_email',
        'change_order_status',
        'add_order_note',
        'update_order_meta',
        'generate_coupon',
    ];

    public function test_core_is_lite_and_on_with_its_steps(): void {
        $core = Modules::manifest()[ Modules::CORE ];

        $this->assertSame( Modules::TIER_LITE, $core['tier'] );
        $this->assertTrue( $core['on'] );
        $this->assertSame( self::CORE_STEPS, array_keys( StepRegistry::from_manifest( [ $core ] ) ) );
    }

    public function test_every_module_entry_is_well_formed(): void {
        foreach ( Modules::manifest() as $id => $module ) {
            $this->assertContains( $module['tier'], [ Modules::TIER_LITE, Modules::TIER_PRO ], $id );
            $this->assertIsBool( $module['on'], $id );
            foreach ( $module['steps'] as $class ) {
                $step = new $class();
                $this->assertInstanceOf( Step::class, $step );
                $this->assertSame( $id, $step->module(), $step->id() . ' is listed under the module it declares' );
            }
        }
    }

    public function test_the_registry_reads_the_manifest(): void {
        $this->assertSame( array_keys( StepRegistry::from_manifest( Modules::manifest() ) ), array_keys( StepRegistry::all() ) );
    }

    public function test_an_off_module_hides_its_steps(): void {
        $manifest                        = Modules::manifest();
        $manifest[ Modules::CORE ]['on'] = false;
        $manifest['extra']               = [
            'tier'  => Modules::TIER_PRO,
            'on'    => true,
            'steps' => [ \YayMatik\Workflow\Steps\Actions\AddOrderNote::class ],
        ];

        $this->assertSame( [], StepRegistry::from_manifest( [ Modules::CORE => $manifest[ Modules::CORE ] ] ) );
        $this->assertSame( [ 'add_order_note' ], array_keys( StepRegistry::from_manifest( $manifest ) ), 'only modules that are on' );
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
