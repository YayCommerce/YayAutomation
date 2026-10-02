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
    }

    public static function initialize() {
        self::get_instance();
    }

    public static function is_prod() {
        return ! defined( 'YAY_AUTOMATION_IS_DEVELOPMENT' ) || YAY_AUTOMATION_IS_DEVELOPMENT !== true;
    }
}
