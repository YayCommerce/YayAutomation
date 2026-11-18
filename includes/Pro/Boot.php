<?php
namespace YayMatik\Pro;

use YayMatik\Engine\Register\ScriptName;
use YayMatik\Pro\Engine\API\CustomerMetaKeysRoute;
use YayMatik\Pro\Workflow\Core\Email\OrderGrid;
use YayMatik\Pro\Workflow\Core\Runtime\InactiveSweep;
use YayMatik\Pro\Workflow\Modules\WoocommerceProModule;
use YayMatik\Pro\Workflow\Modules\YayMatikProModule;
use YayMatik\Workflow\Core\Email\EmailPreview;
use YayMatik\Workflow\Core\Runtime\DailyJobs;
use YayMatik\Workflow\Core\Storage\Settings;
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

    /** @var bool Read once, in `register()`. */
    private static $licensed = false;

    /** Before the plugin boots: the license, then Pro's hooks when it allows them. */
    public static function register(): void {
        AdminShell::register_plugin( new YayMatikProLicenseAdapter() );
        if ( YayMatikProLicenseAdapter::ITEM_ID_IS_TEMPORARY ) {
            UpdateBlock::register();
        }
        // After AdminPage enqueues the app.
        add_action( 'admin_enqueue_scripts', [ self::class, 'localize' ], 20 );
        self::$licensed = YayMatikProLicenseAdapter::is_licensed();
        if ( ! self::$licensed ) {
            return;
        }
        add_filter( ModuleRegistry::HOOK, [ self::class, 'modules' ] );
        CustomerMetaKeysRoute::register();
        foreach ( [
            OrderGrid::CROSS_SELLS => 'order.cross_sell_products',
            OrderGrid::UPSELLS     => 'order.upsell_products',
        ] as $kind => $tag ) {
            EmailPreview::add_sample_need( $kind, $tag, fn( \WC_Order $order ) => (bool) OrderGrid::for_order( $order, $kind ) );
        }
        Settings::allow(
            [
                'abandon_minutes'     => [ 15, 1440 ],
                'cart_retention_days' => [ 7, 365 ],
                'run_retention_days'  => [ 7, 730 ],
            ]
        );
    }

    /** On `init`: Pro's jobs. */
    public static function boot(): void {
        if ( ! self::$licensed ) {
            return;
        }
        InactiveSweep::boot();
        DailyJobs::add( 'inactive_sweep_job', InactiveSweep::HOOK, InactiveSweep::LATE, [ InactiveSweep::class, 'schedule' ], [ InactiveSweep::class, 'needed' ] );
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
        wp_localize_script( ScriptName::PAGE_ADMIN, 'yayMatikPro', [ 'licensed' => self::$licensed ] );
    }
}
