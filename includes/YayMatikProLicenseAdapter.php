<?php
/**
 * Plugin adapter for YayAutomation Pro. Not registered yet — see YayAutomation::register_admin_menu().
 *
 * @package yaycommerce/yay-automation
 */

namespace YayAutomation;

use YayAutomation\Engine\BEPages\AdminPage;

defined( 'ABSPATH' ) || exit;

/**
 * YayAutomation Adapter (Pro)
 */
class YayAutomationProLicenseAdapter implements \YayAutomationScoped\YayCommerce\AdminShell\License\Contracts\LicenseConfigAdapter {
    // --- Menu methods ---
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

    // --- License methods ---
    public function get_plugin_slug(): string {
        return 'yay-automation-pro'; }
    public function get_plugin_name(): string {
        return 'YayAutomation Pro'; }
    public function get_plugin_version(): string {
        return YAY_AUTOMATION_VERSION; }
    public function get_plugin_file(): string {
        return YAY_AUTOMATION_FILE; }
    public function get_item_id(): int {
        return 1; } // TODO: EDD setup
    public function get_store_url(): string {
        return 'https://yaycommerce.com/'; }
    public function get_store_link(): string {
        return 'https://yaycommerce.com/yay-automation/'; }

    /**
     * Check if this plugin has an active, non-expired license.
     */
    public static function is_licensed(): bool {
        $info = get_option( 'yay-automation-pro_license_info', [] );
        $key  = get_option( 'yay-automation-pro_license_key', '' );
        return ! empty( $key ) && ! empty( $info ) && 'expired' !== ( $info['license'] ?? '' );
    }
}
