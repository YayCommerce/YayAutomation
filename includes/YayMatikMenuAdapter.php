<?php

namespace YayMatik;

use YayMatik\Engine\BEPages\AdminPage;

defined( 'ABSPATH' ) || exit;

/** The free plugin's menu adapter: the submenu under YayCommerce. */
class YayMatikMenuAdapter implements \YayMatikScoped\YayCommerce\AdminShell\Contracts\PluginMenuAdapter {
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
}
