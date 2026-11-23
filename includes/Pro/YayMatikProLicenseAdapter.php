<?php
namespace YayMatik\Pro;

use YayMatik\YayMatikMenuAdapter;
use YayMatikScoped\YayCommerce\AdminShell\License\Contracts\LicenseConfigAdapter;
use YayMatikScoped\YayCommerce\AdminShell\License\LicenseState;

defined( 'ABSPATH' ) || exit;

/** YayMatik Pro's menu and license: the Licenses page card, the license check and the updater. */
class YayMatikProLicenseAdapter extends YayMatikMenuAdapter implements LicenseConfigAdapter {

    public const SLUG = 'yaymatik-pro';

    public const ITEM_ID = 69307;

    public function get_plugin_slug(): string {
        return self::SLUG; }
    public function get_plugin_name(): string {
        return 'YayMatik Pro'; }
    public function get_plugin_version(): string {
        return YAYMATIK_VERSION; }
    public function get_plugin_file(): string {
        return YAYMATIK_FILE; }
    public function get_item_id(): int {
        return self::ITEM_ID; }
    public function get_store_url(): string {
        return 'https://yaycommerce.com/'; }
    public function get_store_link(): string {
        return 'https://yaycommerce.com/yaymatik/'; }

    /** Pro features run once a key was activated on this site and until it is revoked; an expired key keeps them. */
    public static function is_licensed(): bool {
        return LicenseState::allows_features( self::SLUG );
    }
}
