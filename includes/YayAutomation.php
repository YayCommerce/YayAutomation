<?php
namespace YayAutomation;

use YayAutomation\Utils\SingletonTrait;

/**
 * YayAutomation Plugin Entry Point
 */
final class YayAutomation {
    use SingletonTrait;

    protected function __construct() {
        \YayAutomation\Engine\Register\RegisterFacade::get_instance();
        \YayAutomation\Engine\BEPages\AdminPage::get_instance();
        \YayAutomation\Engine\API\RestAPI::get_instance();
        \YayAutomation\Engine\Workflows\WorkflowPostType::get_instance();
        \YayAutomation\Engine\Workflows\VersionStore::maybe_install();
        if ( class_exists( 'WooCommerce' ) ) {
            \YayAutomation\Engine\Workflows\Runner::get_instance();
        }

        // TODO: EDD setup
        // if ( ! YayAutomationProLicenseAdapter::is_licensed() ) {
        // return;
        // }

        $this->register_admin_menu();
        // Orders are only read through WC_Order, so HPOS (custom order tables) is safe.
        add_action( 'before_woocommerce_init', [ $this, 'before_woocommerce_init' ] );
    }

    public function before_woocommerce_init() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', YAY_AUTOMATION_FILE, true );
        }
    }

    private function register_admin_menu() {
        \YayAutomationScoped\YayCommerce\AdminShell\AdminShell::boot();
        \YayAutomationScoped\YayCommerce\AdminShell\AdminShell::register_plugin(
            new YayAutomationLicenseAdapter()
            // Pro adds YayAutomationProLicenseAdapter here.
        );
    }

    public static function initialize() {
        self::get_instance();
    }

    public static function is_prod() {
        return ! defined( 'YAY_AUTOMATION_IS_DEVELOPMENT' ) || YAY_AUTOMATION_IS_DEVELOPMENT !== true;
    }
}
