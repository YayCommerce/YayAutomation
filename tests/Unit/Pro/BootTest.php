<?php
namespace YayMatik\Tests\Unit\Pro;

use PHPUnit\Framework\TestCase;
use YayMatik\Pro\Boot;
use YayMatik\Pro\Workflow\Modules\WoocommerceProModule;
use YayMatik\Pro\Workflow\Modules\YayMatikProModule;
use YayMatik\Pro\YayMatikProLicenseAdapter;
use YayMatik\Tests\Support\ProLicense;
use YayMatik\Workflow\Core\Definition\ProCatalog;
use YayMatik\Workflow\ModuleRegistry;
use YayMatik\Workflow\Modules\WoocommerceModule;
use YayMatik\Workflow\Modules\YayMatikModule;
use YayMatikScoped\YayCommerce\AdminShell\AdminShell;

/** What the license lets Pro load. */
final class BootTest extends TestCase {

    /** @var array */
    private $filters = [];

    protected function setUp(): void {
        $this->filters                    = $GLOBALS['yaymatik_test_filters'] ?? [];
        $GLOBALS['yaymatik_test_filters'] = [];
        AdminShell::$registered           = [];
    }

    protected function tearDown(): void {
        $GLOBALS['yaymatik_test_filters'] = $this->filters;
        $GLOBALS['yaymatik_test_options'] = [];
    }

    public function test_the_test_run_loads_the_pro_modules(): void {
        $GLOBALS['yaymatik_test_filters'] = $this->filters;

        $this->assertSame( [ YayMatikProModule::class, WoocommerceProModule::class ], ModuleRegistry::classes() );
    }

    public function test_pro_modules_register_cores_steps_and_pros_under_the_same_plugins(): void {
        $core = ModuleRegistry::plugins( ModuleRegistry::from( [ YayMatikModule::class, WoocommerceModule::class ] ) );
        $pro  = ModuleRegistry::from( Boot::modules( [ YayMatikModule::class, WoocommerceModule::class ] ) );

        $this->assertSame( [ 'yaymatik', 'woocommerce' ], array_keys( $pro ) );
        $this->assertSame( $core, array_intersect_key( ModuleRegistry::plugins( $pro ), $core ) );
        $this->assertEqualsCanonicalizing( array_keys( ProCatalog::STEPS ), array_keys( array_diff_key( ModuleRegistry::plugins( $pro ), $core ) ) );
        $this->assertSame( 'yaymatik', ModuleRegistry::plugins( $pro )['wait_until'] );
    }

    public function test_another_plugins_module_is_kept(): void {
        $this->assertSame( [ YayMatikProModule::class, 'Acme\\Module' ], Boot::modules( [ YayMatikModule::class, 'Acme\\Module' ] ) );
    }

    public function test_the_license_adapter_is_registered_without_a_license(): void {
        Boot::register();

        $this->assertCount( 1, AdminShell::$registered );
        $this->assertInstanceOf( YayMatikProLicenseAdapter::class, AdminShell::$registered[0] );
        $this->assertSame( 'yaymatik-pro', AdminShell::$registered[0]->get_plugin_slug() );
    }

    /** @return array */
    public function licenses(): array {
        return [
            'no key'   => [ [], false ],
            'disabled' => [ ProLicense::active( 'disabled' ), false ],
            'valid'    => [ ProLicense::active(), true ],
            'expired'  => [ ProLicense::active( 'expired', '2020-01-01 23:59:59' ), true ],
        ];
    }

    /**
     * @dataProvider licenses
     * @param array $options
     * @param bool  $pro
     */
    public function test_pro_modules_load_only_while_the_license_allows_features( array $options, bool $pro ): void {
        if ( defined( 'YayMatikScoped\YayCommerce\AdminShell\STAND_IN' ) ) {
            $this->markTestSkipped( 'The license policy is admin-shell\'s LicenseState: run ./run.sh setup.' );
        }
        $GLOBALS['yaymatik_test_options'] = $options;

        Boot::register();

        $this->assertSame( $pro, YayMatikProLicenseAdapter::is_licensed() );
        $this->assertSame( $pro ? [ YayMatikProModule::class, WoocommerceProModule::class ] : [ YayMatikModule::class, WoocommerceModule::class ], ModuleRegistry::classes() );
    }
}
