<?php
namespace YayMatik;

use YayMatik\Utils\SingletonTrait;

/** Boots the plugin. */
final class YayMatik {
    use SingletonTrait;

    protected function __construct() {
        \YayMatik\Engine\Register\RegisterFacade::get_instance();
        \YayMatik\Engine\BEPages\AdminPage::get_instance();
        \YayMatik\Engine\API\RestAPI::get_instance();
        \YayMatik\Engine\FEPages\UnsubscribePage::get_instance();
        \YayMatik\Workflow\Core\Storage\Schema::maybe_install();
        if ( class_exists( 'WooCommerce' ) ) {
            \YayMatik\Workflow\Core\Runtime\Runtime::boot();
        }

        $this->register_admin_menu();
        // Orders are only read through WC_Order, so HPOS (custom order tables) is safe.
        add_action( 'before_woocommerce_init', [ $this, 'before_woocommerce_init' ] );
    }

    public function before_woocommerce_init() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', YAYMATIK_FILE, true );
        }
    }

    private function register_admin_menu() {
        \YayMatikScoped\YayCommerce\AdminShell\AdminShell::boot();
        \YayMatikScoped\YayCommerce\AdminShell\AdminShell::register_plugin(
            new YayMatikLicenseAdapter()
        );
    }

    public static function initialize() {
        self::get_instance();
    }

    public static function is_prod() {
        return ! defined( 'YAYMATIK_IS_DEVELOPMENT' ) || YAYMATIK_IS_DEVELOPMENT !== true;
    }
}
