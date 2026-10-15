<?php
/**
 * Plugin adapter for YayMatik Pro. Not registered yet — see YayMatik::register_admin_menu().
 *
 * @package yaycommerce/yaymatik
 */

namespace YayMatik;

use YayMatik\Engine\BEPages\AdminPage;

defined( 'ABSPATH' ) || exit;

/**
 * YayMatik Adapter (Pro)
 */
class YayMatikProLicenseAdapter implements \YayMatikScoped\YayCommerce\AdminShell\License\Contracts\LicenseConfigAdapter {
    // --- Menu methods ---
    public function get_menu_title(): string {
        return 'YayMatik'; }
    public function get_page_title(): string {
        return 'YayMatik'; }
    public function get_menu_slug(): string {
        return AdminPage::MENU_SLUG; }
    public function get_settings_page_callback(): ?callable {
        return [ AdminPage::get_instance(), 'render_page' ]; }
    public function get_settings_page_position(): ?int {
        return YAYMATIK_MENU_ORDER; }
    public function get_capability(): string {
        return AdminPage::CAPABILITY; }
    public function get_plugin_basename(): string {
        return YAYMATIK_BASE_NAME; }
    public function get_settings_label(): string {
        return __( 'Workflows', 'yaymatik' ); }
    public function get_docs_url(): string {
        return 'https://docs.yaycommerce.com/'; }
    public function get_pro_url(): string {
        return ''; }

    // --- License methods ---
    public function get_plugin_slug(): string {
        return 'yaymatik-pro'; }
    public function get_plugin_name(): string {
        return 'YayMatik Pro'; }
    public function get_plugin_version(): string {
        return YAYMATIK_VERSION; }
    public function get_plugin_file(): string {
        return YAYMATIK_FILE; }
    public function get_item_id(): int {
        return 1; } // TODO: EDD setup
    public function get_store_url(): string {
        return 'https://yaycommerce.com/'; }
    public function get_store_link(): string {
        return 'https://yaycommerce.com/yaymatik/'; }

    /**
     * Check if this plugin has an active, non-expired license.
     */
    public static function is_licensed(): bool {
        $info = get_option( 'yaymatik-pro_license_info', [] );
        $key  = get_option( 'yaymatik-pro_license_key', '' );
        return ! empty( $key ) && ! empty( $info ) && 'expired' !== ( $info['license'] ?? '' );
    }
}
