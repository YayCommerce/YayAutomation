<?php

namespace YayMatik;

use YayMatik\Engine\BEPages\AdminPage;

defined( 'ABSPATH' ) || exit;

/** Plugin adapter for YayMatik Pro (not registered). */
class YayMatikProLicenseAdapter implements \YayMatikScoped\YayCommerce\AdminShell\License\Contracts\LicenseConfigAdapter {
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

    public function get_plugin_slug(): string {
        return 'yaymatik-pro'; }
    public function get_plugin_name(): string {
        return 'YayMatik Pro'; }
    public function get_plugin_version(): string {
        return YAYMATIK_VERSION; }
    public function get_plugin_file(): string {
        return YAYMATIK_FILE; }
    public function get_item_id(): int {
        return 1; }
    public function get_store_url(): string {
        return 'https://yaycommerce.com/'; }
    public function get_store_link(): string {
        return 'https://yaycommerce.com/yaymatik/'; }

    /** An active, non-expired license. */
    public static function is_licensed(): bool {
        $info = get_option( 'yaymatik-pro_license_info', [] );
        $key  = get_option( 'yaymatik-pro_license_key', '' );
        return ! empty( $key ) && ! empty( $info ) && 'expired' !== ( $info['license'] ?? '' );
    }
}
