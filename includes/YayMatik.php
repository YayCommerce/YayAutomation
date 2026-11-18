<?php
namespace YayMatik;

use YayMatik\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/** Boots the plugin. */
final class YayMatik {
    use SingletonTrait;

    protected function __construct() {
        \YayMatik\Engine\Register\RegisterFacade::get_instance();
        \YayMatik\Engine\BEPages\AdminPage::get_instance();
        \YayMatik\Engine\API\RestAPI::get_instance();
        \YayMatik\Engine\API\SettingsRoutes::get_instance();
        \YayMatik\Engine\FEPages\UnsubscribePage::get_instance();
        \YayMatik\Workflow\Core\Storage\Schema::maybe_install();
        \YayMatik\Workflow\Core\Runtime\Runtime::boot();
        \YayMatik\Workflow\Core\Cart\CartTracker::boot();
        // Not in CartTracker::boot(): links in emails already sent work while tracking is off.
        \YayMatik\Engine\FEPages\RestoreCart::get_instance();
        // Not behind cart tracking either: carts saved before it was turned off still expire and can be erased.
        \YayMatik\Workflow\Core\Runtime\Cleanup::boot();
        \YayMatik\Workflow\Core\Cart\CartSweep::boot();
        \YayMatik\Engine\Privacy\PersonalData::get_instance();
        \YayMatik\Engine\API\HealthRoutes::get_instance();
        \YayMatik\Engine\API\SearchRoutes::get_instance();

        // Orders are only read through WC_Order, so HPOS (custom order tables) is safe.
        add_action( 'before_woocommerce_init', [ $this, 'before_woocommerce_init' ] );
    }

    public function before_woocommerce_init() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', YAYMATIK_FILE, true );
        }
    }

    /** YayMatik Pro registers its own menu, with its license, and its modules before anything reads them. */
    public static function initialize() {
        if ( class_exists( \YayMatik\Pro\Boot::class ) ) {
            \YayMatik\Pro\Boot::register();
            // Before Action Scheduler's init (priority 1), where Cleanup schedules the daily jobs.
            add_action( 'init', [ \YayMatik\Pro\Boot::class, 'boot' ], 0 );
        } else {
            \YayMatikScoped\YayCommerce\AdminShell\AdminShell::register_plugin( new YayMatikMenuAdapter() );
        }
        self::get_instance();
    }

    public static function is_prod() {
        return ! defined( 'YAYMATIK_IS_DEVELOPMENT' ) || YAYMATIK_IS_DEVELOPMENT !== true;
    }
}
