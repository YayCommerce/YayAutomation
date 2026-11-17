<?php
namespace YayMatik\Pro;

use YayMatik\Engine\Register\ScriptName;
use YayMatik\Pro\Workflow\Modules\WoocommerceProModule;
use YayMatik\Pro\Workflow\Modules\YayMatikProModule;
use YayMatik\Workflow\ModuleRegistry;
use YayMatik\Workflow\Modules\WoocommerceModule;
use YayMatik\Workflow\Modules\YayMatikModule;
use YayMatikScoped\YayCommerce\AdminShell\AdminShell;

defined( 'ABSPATH' ) || exit;

/** Wires YayMatik Pro into the plugin. Without a license the site runs as YayMatik. */
final class Boot {

    /** Pro module by the core module it extends. */
    private const MODULES = [
        YayMatikModule::class    => YayMatikProModule::class,
        WoocommerceModule::class => WoocommerceProModule::class,
    ];

    /** Before the plugin boots: the license, then Pro's hooks when it allows them. */
    public static function register(): void {
        AdminShell::register_plugin( new YayMatikProLicenseAdapter() );
        if ( YayMatikProLicenseAdapter::ITEM_ID_IS_TEMPORARY ) {
            UpdateBlock::register();
        }
        // After AdminPage enqueues the app.
        add_action( 'admin_enqueue_scripts', [ self::class, 'localize' ], 20 );
        if ( ! YayMatikProLicenseAdapter::is_licensed() ) {
            return;
        }
        add_filter( ModuleRegistry::HOOK, [ self::class, 'modules' ] );
    }

    /**
     * @param array $modules
     * @return array
     */
    public static function modules( array $modules ): array {
        return array_map( fn( $module ) => is_string( $module ) ? ( self::MODULES[ $module ] ?? $module ) : $module, $modules );
    }

    /** `window.yayMatikPro` on the YayMatik page; shape must match apps/admin-page/src/pro/types.d.ts. */
    public static function localize(): void {
        if ( ! wp_script_is( ScriptName::PAGE_ADMIN, 'enqueued' ) ) {
            return;
        }
        wp_localize_script( ScriptName::PAGE_ADMIN, 'yayMatikPro', [ 'licensed' => YayMatikProLicenseAdapter::is_licensed() ] );
    }
}
