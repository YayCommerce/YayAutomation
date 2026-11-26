<?php
/**
 * Patches the scoped admin-shell in a build folder; fails with file and line when the copy doesn't match.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- a build script, run by php-cli without WordPress.

// Every edition: each shipped file stops when it is requested directly. The free zip (WordPress.org) also keeps only the
// YayCommerce menu and the Recommended Plugins page: no license client, updater, license page or license registry, an
// installer that only installs its own WordPress.org list, and a copy that never wins the election over a full one (it
// would draw every YayCommerce page, Licenses included, for the other Yay plugins on the site). Exact-text patches: an
// admin-shell update that changes a patched text fails the build. Release.sh removes src/License first.
// Usage: php free-admin-shell.php <build>/vendor-prefixed [--abspath-only]

$dir          = rtrim( $argv[1] ?? '', '/' );
$abspath_only = in_array( '--abspath-only', $argv, true );
$fail         = function ( string $message ) {
    fwrite( STDERR, "admin-shell build patch: {$message}\n" );
    exit( 1 );
};
$php_files    = function () use ( $dir ) {
    $found = [];
    foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
        if ( 'php' === $file->getExtension() ) {
            $found[ substr( $file->getPathname(), strlen( $dir ) + 1 ) ] = $file->getPathname();
        }
    }
    ksort( $found );
    return $found;
};
// The guard itself, at the start of a line: `defined('ABSPATH') || exit;` or `if (!defined('ABSPATH')) {`.
$abspath_guard = '/^\s*(?:\\\\?defined\(\s*[\'"]ABSPATH[\'"]\s*\)\s*(?:\|\||or)\s*(?:exit|die)|if\s*\(\s*!\s*\\\\?defined\(\s*[\'"]ABSPATH[\'"]\s*\)\s*\))/m';

if ( '' === $dir || ! is_dir( $dir . '/src' ) ) {
    $fail( 'usage: php free-admin-shell.php <build>/vendor-prefixed [--abspath-only]' );
}

foreach ( $php_files() as $short => $target ) {
    $code = (string) file_get_contents( $target );
    if ( preg_match( $abspath_guard, $code ) ) {
        continue;
    }
    $guarded = preg_replace( '/^(namespace [^;]+;\n)/m', "$1\ndefined('ABSPATH') || exit;\n", $code, 1, $count );
    if ( 1 !== $count ) {
        $fail( "{$short}: no namespace line to put the ABSPATH guard after." );
    }
    file_put_contents( $target, $guarded );
}
if ( $abspath_only ) {
    echo "admin-shell: ABSPATH guard in every file.\n";
    exit( 0 );
}

if ( is_dir( $dir . '/src/License' ) ) {
    $fail( "{$dir}/src/License must be removed first." );
}
if ( ! preg_match( '/^namespace (\w+\\\\YayCommerce\\\\AdminShell);/m', (string) file_get_contents( $dir . '/src/AdminShell.php' ), $m ) ) {
    $fail( 'src/AdminShell.php: no scoped AdminShell namespace found.' );
}
$ns = $m[1];

// The license pages, cards and registry; AddonHostAdapter only feeds addon licenses into that registry.
$removed = [
    'src/Pages/LicensesPage.php',
    'views/licenses-page.php',
    'views/license-card.php',
    'views/license-activate-card.php',
    'assets/js/license.js',
    'assets/css/licenses.css',
    'src/Registry/AddonBridge.php',
    'src/Registry/AddonLicenseAdapter.php',
    'src/Registry/LegacyBridge.php',
    'src/Registry/LicenseRegistry.php',
    'src/Registry/PluginLicenseInfo.php',
    'src/Contracts/AddonHostAdapter.php',
];
foreach ( $removed as $file ) {
    if ( ! is_file( "{$dir}/{$file}" ) ) {
        $fail( "{$file}: expected in admin-shell, not found." );
    }
    unlink( "{$dir}/{$file}" );
}
$left = array_diff( scandir( "{$dir}/src/Registry" ), [ '.', '..' ] );
if ( $left ) {
    $fail( 'src/Registry: new files beside the license registry: ' . implode( ', ', $left ) );
}
rmdir( "{$dir}/src/Registry" );

// The installer, rewritten: nonce, then capability, then input; it installs only a slug from recommended-plugins-data.php,
// from its WordPress.org download link, and updates or activates only an installed plugin of that list. Errors carry a
// message, never the exception, with status 200 as the page's script expects (only install has an error callback).
// It answers only its own page (`shell=wporg`): a full copy or an older Yay plugin may hook the same actions for a page
// that sends a package URL, and their handlers answer theirs.
$handlers = <<<'PHP'
    public function ajax_get_plugin_data(): void
    {
        if ('wporg' !== ($_POST['shell'] ?? '')) {
            return;
        }
        if (!check_ajax_referer('yay_recommended_nonce', 'nonce', \false)) {
            wp_send_json_error(['mess' => __('Nonce is invalid', 'yaycommerce')]);
        }
        if (!current_user_can('install_plugins')) {
            wp_send_json_error(['mess' => __('Permission denied', 'yaycommerce')]);
        }
        $tab = isset($_POST['tab']) ? sanitize_key(wp_unslash($_POST['tab'])) : '';
        if ('' === $tab) {
            wp_send_json_error(['mess' => 'Missing tab']);
        }
        require_once \ABSPATH . 'wp-admin/includes/plugin-install.php';
        $recommended_data = apply_filters('yay_recommended_plugins_excluded', self::get_other_plugins());
        $recommended_plugins = [];
        foreach ($recommended_data as $key => $plugin) {
            if ('all' === $tab || in_array($tab, $plugin['type'], \true)) {
                $recommended_plugins[$key] = $plugin;
            }
        }
        ob_start();
        try {
            include __DIR__ . '/../../views/recommended-plugins-page.php';
        } catch (\Throwable $ex) {
            ob_end_clean();
            wp_send_json_error(['mess' => __('Error.', 'yaycommerce')]);
        }
        wp_send_json_success(['mess' => __('Get data success', 'yaycommerce'), 'html' => ob_get_clean()]);
    }
    public function ajax_activate_plugin(): void
    {
        if ('wporg' !== ($_POST['shell'] ?? '')) {
            return;
        }
        if (!check_ajax_referer('yay_recommended_nonce', 'nonce', \false)) {
            wp_send_json_error(['mess' => __('Nonce is invalid', 'yaycommerce')]);
        }
        if (!current_user_can('activate_plugins')) {
            wp_send_json_error(['mess' => __('Permission denied', 'yaycommerce')]);
        }
        $file = isset($_POST['file']) ? sanitize_text_field(wp_unslash($_POST['file'])) : '';
        if (!$this->is_listed_plugin_file($file)) {
            wp_send_json_error(['mess' => 'Not a recommended plugin']);
        }
        $result = activate_plugin($file);
        if (is_wp_error($result)) {
            wp_send_json_error(['mess' => $result->get_error_message()]);
        }
        wp_send_json_success(['mess' => __('Activate success', 'yaycommerce')]);
    }
    public function ajax_upgrade_plugin(): void
    {
        if ('wporg' !== ($_POST['shell'] ?? '')) {
            return;
        }
        if (!check_ajax_referer('yay_recommended_nonce', 'nonce', \false)) {
            wp_send_json_error(['mess' => __('Nonce is invalid', 'yaycommerce')]);
        }
        if (!current_user_can('install_plugins')) {
            wp_send_json_error(['mess' => __('Permission denied', 'yaycommerce')]);
        }
        require_once \ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once \ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once \ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
        require_once \ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
        $type = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : 'install';
        try {
            $upgrader = new \Plugin_Upgrader(new \WP_Ajax_Upgrader_Skin());
            if ('install' === $type) {
                $slug = isset($_POST['slug']) ? sanitize_key(wp_unslash($_POST['slug'])) : '';
                if (!in_array($slug, array_column(self::get_other_plugins(), 'slug'), \true)) {
                    wp_send_json_error(['mess' => 'Not a recommended plugin'], 400);
                }
                $plugin_data = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => \false, 'reviews' => \false, 'banners' => \false, 'icons' => \false]]);
                $link = !is_wp_error($plugin_data) && isset($plugin_data->download_link) ? (string) $plugin_data->download_link : '';
                if (0 !== strpos($link, 'https://downloads.wordpress.org/')) {
                    wp_send_json_error(['mess' => 'No WordPress.org download for this plugin'], 400);
                }
                $result = $upgrader->install($link);
                if (is_wp_error($result) || !$result) {
                    wp_send_json_error(['mess' => is_wp_error($result) ? $result->get_error_message() : 'Install failed']);
                }
                $install_status = install_plugin_install_status($plugin_data);
                $active_plugin = activate_plugin($install_status['file']);
                if (is_wp_error($active_plugin)) {
                    wp_send_json_error(['mess' => $active_plugin->get_error_message()]);
                }
                wp_send_json_success(['mess' => __('Install success', 'yaycommerce')]);
            }
            $plugin = isset($_POST['plugin']) ? sanitize_text_field(wp_unslash($_POST['plugin'])) : '';
            if (!$this->is_listed_plugin_file($plugin)) {
                wp_send_json_error(['mess' => 'Not a recommended plugin']);
            }
            $is_active = is_plugin_active($plugin);
            $result = $upgrader->upgrade($plugin);
            if (is_wp_error($result)) {
                wp_send_json_error(['mess' => $result->get_error_message()]);
            }
            activate_plugin($plugin);
            wp_send_json_success(['mess' => __('Update success', 'yaycommerce'), 'active' => $is_active]);
        } catch (\Throwable $ex) {
            wp_send_json_error(['mess' => __('Error.', 'yaycommerce')]);
        }
    }
    private function is_listed_plugin_file(string $file): bool
    {
        if ('' === $file || !array_key_exists($file, get_plugins())) {
            return \false;
        }
        foreach (self::get_other_plugins() as $plugin) {
            if (dirname($file) === $plugin['slug'] || $this->check_pro_version_exists($plugin) === $file) {
                return \true;
            }
        }
        return \false;
    }

PHP;

// [ file, exact text, replacement, times ]. `{NS}` is the scoped AdminShell namespace.
$patches = [
    // Version election and the AJAX guard: 0.0.1, so any full copy wins, and no registry for the winner to merge.
    [ 'src/AdminShell.php', "\$GLOBALS['yaycommerce_admin_shell_versions'][self::\$prefix] = ['version' => self::VERSION, 'registry' => \$instance->registry, 'boot_cb'", "\$GLOBALS['yaycommerce_admin_shell_versions'][self::\$prefix] = ['version' => '0.0.1', 'boot_cb'" ],
    [ 'src/AdminShell.php', "            if (\\version_compare(self::VERSION, \$registered_ver, '>')) {\n                \$GLOBALS['yaycommerce_ajax_handlers_registered'] = self::VERSION;", "            if (\\version_compare('0.0.1', \$registered_ver, '>')) {\n                \$GLOBALS['yaycommerce_ajax_handlers_registered'] = '0.0.1';" ],
    [ 'src/AdminShell.php', "use {NS}\\Contracts\\AddonHostAdapter;\n", '' ],
    [ 'src/AdminShell.php', "use {NS}\\License\\Contracts\\LicenseConfigAdapter;\nuse {NS}\\License\\LicenseHandler;\nuse {NS}\\License\\PluginInfoFactory;\n", '' ],
    [ 'src/AdminShell.php', "use {NS}\\Registry\\AddonBridge;\nuse {NS}\\Registry\\LegacyBridge;\nuse {NS}\\Registry\\LicenseRegistry;\n", '' ],
    [ 'src/AdminShell.php', "    private LicenseRegistry \$registry;\n    private function __construct()\n    {\n        \$this->registry = new LicenseRegistry();\n    }", "    private function __construct()\n    {\n    }" ],
    [ 'src/AdminShell.php', "        \$legacy_bridge = new LegacyBridge(\$instance->registry);\n        \$legacy_bridge->init();\n", '' ],
    [ 'src/AdminShell.php', "        \$instance = self::get_instance();\n        // Merge all registries into the winner's registry\n        foreach (\$GLOBALS['yaycommerce_admin_shell_versions'] ?? [] as \$prefix => \$data) {\n            if (\$prefix !== self::\$prefix && isset(\$data['registry'])) {\n                \$other_registry = \$data['registry'];\n                foreach (\$other_registry->all() as \$info) {\n                    if (!\$instance->registry->get(\$info->slug)) {\n                        \$instance->registry->register(\$info);\n                    }\n                }\n            }\n        }\n", '' ],
    [ 'src/AdminShell.php', 'new PagesRouter($instance->registry);', 'new PagesRouter();' ],
    [ 'src/AdminShell.php', "        if (\$adapter instanceof LicenseConfigAdapter) {\n            self::enable_license(\$adapter);\n        }\n        // Addon host — bridge plugin-specific addon filter into registry.\n        if (\$adapter instanceof AddonHostAdapter) {\n            \$filter_name = \$adapter->get_addon_licensing_filter();\n            if (!empty(\$filter_name)) {\n                \$bridge = new AddonBridge(\$filter_name, self::get_instance()->registry);\n                \$bridge->init();\n            }\n        }\n", '' ],
    [ 'src/AdminShell.php', "        if (\$adapter instanceof LicenseConfigAdapter) {\n            \$slug = \$adapter->get_plugin_slug();\n            if (empty(\$slug)) {\n                trigger_error('[YayCommerce AdminShell] get_plugin_slug() must return a non-empty string.', \\E_USER_WARNING);\n            }\n            \$item_id = \$adapter->get_item_id();\n            if (\$item_id <= 0) {\n                trigger_error('[YayCommerce AdminShell] get_item_id() must return a positive integer.', \\E_USER_WARNING);\n            }\n            \$store_url = \$adapter->get_store_url();\n            if (empty(\$store_url)) {\n                trigger_error('[YayCommerce AdminShell] get_store_url() must return a non-empty URL.', \\E_USER_WARNING);\n            }\n            \$plugin_file = \$adapter->get_plugin_file();\n            if (empty(\$plugin_file)) {\n                trigger_error('[YayCommerce AdminShell] get_plugin_file() must return a non-empty path.', \\E_USER_WARNING);\n            }\n        }\n", '' ],
    [ 'src/AdminShell.php', "    public static function enable_license(LicenseConfigAdapter \$adapter): void\n    {\n        \$slug = \$adapter->get_plugin_slug();\n        if (isset(self::\$enabled_slugs[\$slug])) {\n            return;\n        }\n        self::\$enabled_slugs[\$slug] = \\true;\n        \$instance = self::get_instance();\n        new LicenseHandler(\$adapter);\n        \$info = PluginInfoFactory::from_adapter(\$adapter);\n        \$instance->registry->register(\$info);\n        do_action('yaycommerce_admin_shell_license_enabled', \$adapter);\n    }\n", '' ],
    // No Go Pro link and no hard-coded Support row link on the Plugins screen.
    [ 'src/AdminShell.php', "            \$pro_url = \$adapter->get_pro_url();\n            if (!empty(\$pro_url)) {\n                \$new['go-pro'] = '<a href=\"' . esc_url(\$pro_url) . '\" target=\"_blank\" style=\"color:#00a32a;font-weight:700;\">' . esc_html__('Go Pro', 'yaycommerce') . '</a>';\n            }\n", '' ],
    [ 'src/AdminShell.php', "            \$meta[] = '<a href=\"https://yaycommerce.com/support\" target=\"_blank\">' . esc_html__('Support', 'yaycommerce') . '</a>';\n", '' ],
    [ 'src/AdminShell.php', "    public static function registry(): LicenseRegistry\n    {\n        return self::get_instance()->registry;\n    }\n", '' ],
    // No Licenses submenu.
    [ 'src/Menu/PagesRouter.php', "use {NS}\\Pages\\LicensesPage;\n", '' ],
    [ 'src/Menu/PagesRouter.php', "use {NS}\\Registry\\LicenseRegistry;\n", '' ],
    [ 'src/Menu/PagesRouter.php', "    private LicenseRegistry \$registry;\n    public function __construct(LicenseRegistry \$registry)\n    {\n        \$this->registry = \$registry;\n    }\n", '' ],
    [ 'src/Menu/PagesRouter.php', "        \$has_any = !empty(\$this->registry->all());\n        if (\$has_any) {\n            \$submenus['yaycommerce-licenses'] = ['parent' => 'yaycommerce', 'name' => __('Licenses', 'yaycommerce'), 'capability' => \$capability, 'render_callback' => [LicensesPage::class, 'render'], 'load_callback' => [LicensesPage::class, 'load_data'], 'position' => 910];\n        }\n", '' ],
    // The plugin's own submenu: no redirect to a Licenses page.
    [ 'src/Menu/PluginSubmenu.php', "use {NS}\\License\\Contracts\\LicenseConfigAdapter;\nuse {NS}\\License\\License;\n", '' ],
    [ 'src/Menu/PluginSubmenu.php', "                    if (method_exists(\$this->adapter, 'is_licensed') && \$this->adapter->is_licensed() || !method_exists(\$this->adapter, 'is_licensed')) {\n                        remove_submenu_page('yaycommerce', \$menu_slug);\n                        \$is_override = \\true;\n                    } else {\n                        \$has_menu = \\true;\n                    }\n", "                    remove_submenu_page('yaycommerce', \$menu_slug);\n                    \$is_override = \\true;\n" ],
    [ 'src/Menu/PluginSubmenu.php', "        \$needs_redirect = \\false;\n        if (\$this->adapter instanceof LicenseConfigAdapter) {\n            \$license = new License(\$this->adapter);\n            if (!\$license->is_active()) {\n                \$needs_redirect = \\true;\n                \$callback = null;\n            }\n        }\n", '' ],
    [ 'src/Menu/PluginSubmenu.php', "if (!\$needs_redirect && method_exists(\$this->adapter, 'needs_woocommerce_screen')", "if (method_exists(\$this->adapter, 'needs_woocommerce_screen')" ],
    [ 'src/Menu/PluginSubmenu.php', "        if (\$needs_redirect) {\n            add_action('load-' . \$page_id, [__CLASS__, 'redirect_to_licenses']);\n        }\n", '' ],
    [ 'src/Menu/PluginSubmenu.php', "    public static function redirect_to_licenses(): void\n    {\n        // The load hook fires in the current context — target the matching dashboard\n        // so a network-flagged pro plugin redirects within Network Admin, not the site.\n        \$path = 'admin.php?page=yaycommerce-licenses';\n        \$url = AdminContext::is_network() ? network_admin_url(\$path) : admin_url(\$path);\n        wp_safe_redirect(\$url);\n        exit;\n    }\n", '' ],
    // Install by slug: the page sends the slug, never a package URL.
    [ 'views/recommended-plugins-page.php', "data-plugin-url=\"<?php \n        echo \\esc_url(\$plugin_detail['download_link'] ?? '');", "data-plugin-slug=\"<?php \n        echo \\esc_attr(\$plugin_slug);" ],
    [ 'views/recommended-plugins-page.php', "data-install-url=\"<?php \n        echo \\esc_url(\$plugin_detail['download_link'] ?? '');", "data-plugin-slug=\"<?php \n        echo \\esc_attr(\$plugin_slug);" ],
    [ 'assets/js/other-plugins.js', 'data-install-url', 'data-plugin-slug', 3 ],
    [ 'assets/js/other-plugins.js', "[data-plugin-url='\" + plugin + \"']", "[data-plugin-slug='\" + plugin + \"']" ],
    [ 'assets/js/other-plugins.js', "type: 'install', plugin: plugin,", "type: 'install', slug: plugin," ],
    [ 'assets/js/other-plugins.js', 'nonce: yayRecommended.nonce }', "nonce: yayRecommended.nonce, shell: 'wporg' }", 4 ],
    // YayMatik's own entry under its WordPress.org slug: installed and active here, so the page doesn't offer it.
    [ 'views/recommended-plugins-data.php', "'yaymatik' => ['slug' => 'yaymatik',", "'yaymatik' => ['slug' => 'yaymatik-automation-for-woocommerce'," ],
    [ 'views/recommended-plugins-data.php', "'https://downloads.wordpress.org/plugin/yaymatik.zip'", "'https://downloads.wordpress.org/plugin/yaymatik-automation-for-woocommerce.zip'" ],
];

foreach ( $patches as $patch ) {
    list( $file, $from, $to ) = $patch;
    $times                    = $patch[3] ?? 1;
    $from                     = str_replace( '{NS}', $ns, $from );
    $target                   = $dir . '/' . $file;
    $code                     = is_file( $target ) ? (string) file_get_contents( $target ) : '';
    $hits                     = substr_count( $code, $from );
    if ( $times !== $hits ) {
        $fail( "{$file}: expected the text {$times} time(s), found {$hits}:\n{$from}" );
    }
    file_put_contents( $target, str_replace( $from, $to, $code ) );
}

// The three handlers, from the first to the method after them, swapped whole: a change anywhere in them fails here.
$shell_page = $dir . '/src/Pages/RecommendedPluginsPage.php';
$code       = (string) file_get_contents( $shell_page );
$start      = strpos( $code, "    public function ajax_get_plugin_data(): void\n" );
$end        = strpos( $code, "    public function check_pro_version_exists(array \$plugin_detail): ?string\n" );
if ( false === $start || false === $end || 'f1403826ef5404419940c93a997105ea' !== md5( substr( $code, $start, $end - $start ) ) ) {
    $fail( 'src/Pages/RecommendedPluginsPage.php: the AJAX handlers changed; review the new code, then update the replacement and its md5.' );
}
file_put_contents( $shell_page, substr( $code, 0, $start ) . $handlers . substr( $code, $end ) );

// Comments last (the patches above match them): admin-shell's describe the license code this copy leaves out.
foreach ( $php_files() as $short => $target ) {
    $kept = '';
    foreach ( token_get_all( (string) file_get_contents( $target ) ) as $token ) {
        if ( ! is_array( $token ) || ! in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
            $kept .= is_array( $token ) ? $token[1] : $token;
        }
    }
    file_put_contents( $target, $kept );
}

// Nothing left may name the license code.
$bad = [];
foreach ( $php_files() as $short => $target ) {
    if ( preg_match_all( '/^.*(?:\\\\License\\\\|LicenseRegistry|LegacyBridge|AddonBridge|AddonLicenseAdapter|PluginLicenseInfo|LicensesPage|AddonHostAdapter|enable_license|LicenseConfigAdapter|is_licensed|redirect_to_licenses).*$/m', (string) file_get_contents( $target ), $lines ) ) {
        foreach ( $lines[0] as $line ) {
            $bad[] = "{$short}: " . trim( $line );
        }
    }
}
if ( $bad ) {
    $fail( "License code still named:\n" . implode( "\n", $bad ) );
}
echo 'admin-shell: ABSPATH guard in every file; ' . count( $removed ) . ' license files removed, ' . count( $patches ) . " patches, installer replaced, comments removed.\n";
