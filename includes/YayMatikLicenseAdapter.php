<?php
/**
 * Plugin adapter for YayAutomation (Lite).
 *
 * @package yaycommerce/yay-automation
 */

namespace YayAutomation;

use YayAutomation\Engine\BEPages\AdminPage;

defined( 'ABSPATH' ) || exit;

/**
 * YayAutomation Adapter (Lite) — submenu under YayCommerce.
 */
class YayAutomationLicenseAdapter implements \YayAutomationScoped\YayCommerce\AdminShell\Contracts\PluginMenuAdapter {
    public function get_menu_title(): string {
        return 'YayAutomation'; }
    public function get_page_title(): string {
        return 'YayAutomation'; }
    public function get_menu_slug(): string {
        return AdminPage::MENU_SLUG; }
    public function get_settings_page_callback(): ?callable {
        return [ AdminPage::get_instance(), 'render_page' ]; }
    public function get_settings_page_position(): ?int {
        return YAY_AUTOMATION_MENU_ORDER; }
    public function get_capability(): string {
        return AdminPage::CAPABILITY; }
    public function get_plugin_basename(): string {
        return YAY_AUTOMATION_BASE_NAME; }
    public function get_settings_label(): string {
        return __( 'Workflows', 'yay-automation' ); }
    public function get_docs_url(): string {
        return 'https://docs.yaycommerce.com/'; }
    public function get_pro_url(): string {
        return ''; }
}
