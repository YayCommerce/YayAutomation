<?php
namespace YayMatik\Tests\Unit\Ecosystem;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Extension\Declaration;
use YayMatik\Workflow\Extension\Diagnostics;
use YayMatik\Workflow\Extension\ExtensionModule;
use YayMatik\Workflow\Extension\Extensions;
use YayMatik\Workflow\Extension\StepIndex;
use YayMatik\Workflow\Extension\TemplateCheck;
use YayMatik\Workflow\ModuleRegistry;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';

/** The six scaffolds in dev/ecosystem register on their fake adapters, and Browse templates offers their templates. */
final class ScaffoldsTest extends TestCase {

    /** Folder => class prefix. */
    private const PRODUCTS = [
        'yaymail'      => 'YayMail',
        'yaymagic'     => 'YayMagic',
        'yaysmtp'      => 'YaySMTP',
        'yaywholesale' => 'YayWholesale',
        'yayloyalty'   => 'YayLoyalty',
        'yaypricing'   => 'YayPricing',
    ];

    /** Templates that start on a Pro trigger. */
    private const PRO_TEMPLATES = [ 'yaymagic_win_back', 'yaymagic_milestone', 'yayloyalty_points_waiting' ];

    /** @var array Class => property => value, put back after. */
    private $saved = [];

    /** @var array */
    private $filters;

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_options'] = [];
        $this->filters                    = $GLOBALS['yaymatik_test_filters'] ?? [];
        $this->saved                      = [
            ModuleRegistry::class => [ 'modules', 'built' ],
            StepRegistry::class   => [ 'steps' ],
            DataRegistry::class   => [ 'items', 'entities' ],
            Declaration::class    => [ 'items' ],
            StepIndex::class      => [ 'stored' ],
        ];
        foreach ( $this->saved as $class => $names ) {
            $this->saved[ $class ] = [];
            foreach ( $names as $name ) {
                $this->saved[ $class ][ $name ] = self::get( $class, $name );
            }
        }
        self::set( StepIndex::class, 'stored', null );
    }

    protected function tearDown(): void {
        foreach ( $this->saved as $class => $values ) {
            foreach ( $values as $name => $value ) {
                self::set( $class, $name, $value );
            }
        }
        $GLOBALS['yaymatik_test_filters'] = $this->filters;
    }

    /**
     * @param string $class_name
     * @param string $name
     */
    private static function get( string $class_name, string $name ) {
        $property = new \ReflectionProperty( $class_name, $name );
        $property->setAccessible( true );
        return $property->getValue();
    }

    /**
     * @param string $class_name
     * @param string $name
     * @param mixed  $value
     */
    private static function set( string $class_name, string $name, $value ): void {
        $property = new \ReflectionProperty( $class_name, $name );
        $property->setAccessible( true );
        $property->setValue( null, $value );
    }

    /**
     * The six modules, put in the registry beside core's as on a dev site.
     *
     * @return ExtensionModule[] By id.
     */
    private static function register(): array {
        $core      = ModuleRegistry::modules();
        $callbacks = [];
        foreach ( self::PRODUCTS as $folder => $prefix ) {
            $dir = dirname( __DIR__, 3 ) . "/dev/ecosystem/{$folder}/";
            require_once "{$dir}{$prefix}_YayMatik.php";
            require_once "{$dir}{$prefix}_YayMatik_Fake_Adapter.php";
            $declarations = "{$prefix}_YayMatik";
            $adapter      = "{$prefix}_YayMatik_Fake_Adapter";
            $callbacks[]  = [ new $declarations( new $adapter() ), 'register' ];
        }
        $modules = [];
        foreach ( Extensions::collect( array_keys( $core ), ...$callbacks ) as $module ) {
            $modules[ $module->id() ] = $module;
        }
        self::set( ModuleRegistry::class, 'modules', $core + $modules );
        self::set( ModuleRegistry::class, 'built', null );
        self::set( StepRegistry::class, 'steps', null );
        self::set( DataRegistry::class, 'items', null );
        self::set( DataRegistry::class, 'entities', null );
        return $modules;
    }

    public function test_each_scaffold_registers_its_module_with_no_diagnostic(): void {
        $modules = self::register();

        $this->assertSame( array_keys( self::PRODUCTS ), array_keys( $modules ) );
        $this->assertSame( [], Diagnostics::recent( 24 ) );
        $this->assertSame(
            [ 'yaysmtp_email_failed', 'yaywholesale_request_submitted', 'yaywholesale_approved', 'yaywholesale_rejected', 'yayloyalty_points_earned', 'yayloyalty_tier_reached', 'yayloyalty_points_expiring' ],
            array_keys( array_filter( StepRegistry::triggers(), fn( $step ) => isset( $modules[ explode( '_', $step->id() )[0] ] ) ) )
        );
    }

    public function test_every_template_passes_the_check_pro_triggers_only_with_pro(): void {
        $modules   = self::register();
        $templates = array_merge( ...array_map( fn( ExtensionModule $module ) => array_keys( $module->templates() ), array_values( $modules ) ) );

        $this->assertCount( 17, $templates );
        $this->assertSame( YAYMATIK_TEST_LITE ? self::PRO_TEMPLATES : [], array_keys( TemplateCheck::hidden( $modules ) ) );
    }
}
