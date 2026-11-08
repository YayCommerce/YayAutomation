<?php
namespace YayMatik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YayMatik\Pro\Workflow\Modules\WoocommerceProModule;
use YayMatik\Pro\Workflow\Modules\YayMatikProModule;
use YayMatik\Tests\Support\FakeCondition;
use YayMatik\Tests\Support\FakeModule;
use YayMatik\Workflow\Conditions\Criteria;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\DataItems\SiteData;
use YayMatik\Workflow\ModuleRegistry;
use YayMatik\Workflow\Modules\Module;
use YayMatik\Workflow\Modules\WoocommerceModule;
use YayMatik\Workflow\Modules\YayMatikModule;
use YayMatik\Workflow\Core\Steps\StepRegistry;

require_once dirname( __DIR__ ) . '/Support/woocommerce-stubs.php';

/** Which modules load, what they register, and their logos. */
final class ModuleRegistryTest extends TestCase {

    private const FREE = [ YayMatikModule::class, WoocommerceModule::class ];

    private const STEPS = [
        'order_created',
        'order_status_changed',
        'order_paid',
        'order_refunded',
        'order_note_added',
        'cart_abandoned',
        'checkout_abandoned',
        'customer_account_created',
        'customer_inactive',
        'customer_order_count_reaches',
        'customer_total_spend_reaches',
        'send_email',
        'send_internal_email',
        'change_order_status',
        'add_order_note',
        'update_order_meta',
        'generate_coupon',
        'condition',
        'wait',
    ];

    /** @var callable|null */
    private $filter = null;

    protected function tearDown(): void {
        if ( $this->filter ) {
            remove_filter( ModuleRegistry::HOOK, $this->filter );
        }
    }

    public function test_free_set_registers_todays_steps_and_data(): void {
        $modules = ModuleRegistry::from( self::FREE );

        $this->assertSame( [ 'yaymatik', 'woocommerce' ], array_keys( $modules ) );
        $this->assertEqualsCanonicalizing( self::STEPS, array_keys( ModuleRegistry::steps( $modules ) ) );
        $this->assertSame( [ 'site', 'order', 'cart', 'customer', 'refund', 'order_item' ], array_keys( ModuleRegistry::data( $modules ) ) );
        $plugins = ModuleRegistry::plugins( $modules );
        $this->assertSame( 'yaymatik', $plugins['condition'] );
        $this->assertSame(
            [
                'yaymatik'    => 2,
                'woocommerce' => 17,
            ],
            array_count_values( $plugins )
        );
    }

    public function test_pro_set_loads_when_its_classes_exist_and_registers_the_same(): void {
        $this->assertSame( [ YayMatikProModule::class, WoocommerceProModule::class ], ModuleRegistry::classes() );

        $pro = ModuleRegistry::from( ModuleRegistry::classes() );
        $this->assertSame( [ 'yaymatik', 'woocommerce' ], array_keys( $pro ) );
        $this->assertSame( ModuleRegistry::plugins( ModuleRegistry::from( self::FREE ) ), ModuleRegistry::plugins( $pro ) );
    }

    public function test_the_registries_read_the_loaded_modules(): void {
        $this->assertSame( ModuleRegistry::plugins( ModuleRegistry::modules() ), ModuleRegistry::plugins( ModuleRegistry::from( ModuleRegistry::classes() ) ) );
        $this->assertEqualsCanonicalizing( self::STEPS, array_keys( StepRegistry::all() ) );
        $this->assertSame( [ 'site', 'order', 'cart', 'customer', 'refund' ], array_keys( DataRegistry::items() ), 'order_item only through order.items' );
        $this->assertArrayHasKey( 'order_item', DataRegistry::entities() );
    }

    public function test_the_loaded_modules_build_their_steps_once(): void {
        $modules = ModuleRegistry::modules();
        ModuleRegistry::plugins( $modules );

        $this->assertSame( StepRegistry::all(), ModuleRegistry::steps( $modules ) );
        $this->assertNotSame( StepRegistry::all()['condition'], ModuleRegistry::steps( ModuleRegistry::from( self::FREE ) )['condition'], 'another set is built on its own' );
    }

    public function test_a_filter_adds_a_module_whose_steps_name_it(): void {
        $this->filter = fn( array $modules ) => array_merge( $modules, [ FakeModule::class ] );
        add_filter( ModuleRegistry::HOOK, $this->filter );

        $modules = ModuleRegistry::from( ModuleRegistry::classes() );

        $this->assertSame( [ 'yaymatik', 'woocommerce', 'fake' ], array_keys( $modules ) );
        $this->assertSame( 'fake', ModuleRegistry::plugins( $modules )['fake_condition'] );
        $this->assertInstanceOf( FakeCondition::class, ModuleRegistry::steps( $modules )['fake_condition'] );
    }

    public function test_anything_but_a_module_subclass_is_ignored(): void {
        $modules = ModuleRegistry::from( [ \stdClass::class, Module::class, 'No\\Such\\Module', 42, null, YayMatikModule::class ] );

        $this->assertSame( [ 'yaymatik' ], array_keys( $modules ) );
    }

    public function test_a_taken_id_keeps_its_first_owner(): void {
        $modules = ModuleRegistry::from( [ YayMatikModule::class, YayMatikProModule::class, FakeModule::class ] );
        $this->assertInstanceOf( YayMatikModule::class, $modules['yaymatik'] );
        $this->assertNotInstanceOf( YayMatikProModule::class, $modules['yaymatik'] );

        $steps   = ModuleRegistry::steps( $modules );
        $plugins = ModuleRegistry::plugins( $modules );
        $this->assertInstanceOf( Criteria::class, $steps['condition'] );
        $this->assertSame( 'yaymatik', $plugins['condition'], 'Criteria stays with the module that listed it first' );
        $this->assertSame( 'fake', $plugins['fake_condition'], 'a condition listed under actions is left out, not registered twice' );
        $this->assertInstanceOf( SiteData::class, ModuleRegistry::data( $modules )['site'] );
        $this->assertCount( 1, ModuleRegistry::data( $modules ) );
    }

    public function test_to_client_keeps_its_keys(): void {
        $plugins = array_column( ModuleRegistry::to_client(), null, 'id' );

        $this->assertSame( [ 'yaymatik', 'woocommerce' ], array_keys( $plugins ) );
        foreach ( $plugins as $id => $plugin ) {
            $this->assertSame( [ 'id', 'label', 'description', 'active', 'logo' ], array_keys( $plugin ) );
            $this->assertMatchesRegularExpression( '#^https?://.+\.svg$#', $plugin['logo'], $id );
        }
        $this->assertTrue( $plugins['yaymatik']['active'], 'always active' );
    }

    public function test_each_logo_is_an_svg_file_the_plugin_ships(): void {
        foreach ( ModuleRegistry::from( self::FREE ) as $id => $module ) {
            $this->assertSame( YAYMATIK_PLUGIN_URL . "assets/logos/$id.svg", $module->logo() );
            $this->assertFileExists( dirname( __DIR__, 2 ) . "/assets/logos/$id.svg" );
        }
    }
}
