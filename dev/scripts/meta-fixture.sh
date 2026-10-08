#!/bin/bash
# Write the admin app's test fixture: window.yayAutomationMeta from the local site (wp-cli).
cd "$(dirname "$0")/../.." || exit 1
[[ -f yay-automation.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
command -v wp >/dev/null || { echo "Missing: wp-cli"; exit 1; }

out=apps/admin-page/src/test/fixtures/meta.json
mkdir -p "$(dirname "$out")"

# Site-specific and per-request values are replaced, so the file only changes when PHP does.
wp ${WP_PATH:+--path="$WP_PATH"} eval '
$meta = \YayAutomation\Engine\BEPages\AdminPage::client_meta();
$meta["plugin"]["pluginUrl"]  = "https://example.test/wp-content/plugins/yay-automation/";
$meta["wp"]["restRoot"]       = "https://example.test/wp-json/";
$meta["wp"]["restNonce"]      = "test-nonce";
$meta["wp"]["siteUrl"]        = "https://example.test";
$meta["wp"]["adminUrl"]       = "https://example.test/wp-admin/";
$meta["wp"]["adminEmail"]     = "admin@example.test";
$meta["wp"]["timezoneString"] = "UTC";
$meta["wp"]["gmtOffset"]      = 0;
$meta["automation"]["email"]["fromName"]    = "Example Store";
$meta["automation"]["email"]["fromAddress"] = "store@example.test";
$meta["automation"]["email"]["settingsUrl"] = "https://example.test/wp-admin/admin.php?page=wc-settings&tab=email";
echo wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
' > "$out.tmp" && mv "$out.tmp" "$out" || { rm -f "$out.tmp"; echo "wp eval failed"; exit 1; }

echo "Wrote $out"
