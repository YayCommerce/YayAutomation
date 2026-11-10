<?php
/**
 * Patches the scoped admin-shell in a free build folder; fails with file and line when the copy doesn't match.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- a build script, run by php-cli without WordPress.

// The free zip leaves out src/License (no license client or updater on WordPress.org). The rest of admin-shell still
// names those classes on paths that run on any site with another YayCommerce plugin, so each use gets a class_exists
// guard here, and the copy is checked: an admin-shell update that moves or adds a use fails the build.

$dir = rtrim( $argv[1] ?? '', '/' );
if ( '' === $dir || ! is_dir( $dir . '/src' ) ) {
    fwrite( STDERR, "Usage: php free-admin-shell.php <build>/vendor-prefixed\n" );
    exit( 1 );
}
if ( is_dir( $dir . '/src/License' ) ) {
    fwrite( STDERR, "{$dir}/src/License must be removed first.\n" );
    exit( 1 );
}

$fail = function ( string $message ) {
    fwrite( STDERR, "free admin-shell patch: {$message}\n" );
    exit( 1 );
};

// [ file, exact text, replacement ]. `{NS}` is the scoped License namespace, e.g. YayMatikScoped\YayCommerce\AdminShell\License.
$patches = [
    [
        'src/Registry/LegacyBridge.php',
        "return LicenseState::is_past_expiry(\$raw_info['expires'] ?? null) ? 'expired' : 'valid';",
        "return \\class_exists(LicenseState::class) && LicenseState::is_past_expiry(\$raw_info['expires'] ?? null) ? 'expired' : 'valid';",
    ],
    [
        'src/Registry/AddonBridge.php',
        "return LicenseState::is_past_expiry(\$raw_info['expires'] ?? null) ? 'expired' : 'valid';",
        "return \\class_exists(LicenseState::class) && LicenseState::is_past_expiry(\$raw_info['expires'] ?? null) ? 'expired' : 'valid';",
    ],
    [
        'src/Registry/AddonBridge.php',
        'new RestAPI($this->build_addon_adapter($plugin));',
        '\\class_exists(RestAPI::class) && new RestAPI($this->build_addon_adapter($plugin));',
    ],
    [
        'src/Registry/AddonBridge.php',
        "            \$adapter = \$this->build_addon_adapter(\$plugin_data);\n            \$license = new License(\$adapter);",
        "            if (!\\class_exists(License::class)) {\n                continue;\n            }\n            \$adapter = \$this->build_addon_adapter(\$plugin_data);\n            \$license = new License(\$adapter);",
    ],
    [
        'src/Menu/PluginSubmenu.php',
        'if ($this->adapter instanceof LicenseConfigAdapter) {',
        'if (\\class_exists(License::class) && $this->adapter instanceof LicenseConfigAdapter) {',
    ],
    [
        'src/AdminShell.php',
        "        if (\$adapter instanceof LicenseConfigAdapter) {\n            self::enable_license(\$adapter);",
        "        if (\\class_exists(LicenseHandler::class) && \$adapter instanceof LicenseConfigAdapter) {\n            self::enable_license(\$adapter);",
    ],
    [
        'src/AdminShell.php',
        "        if (\$adapter instanceof LicenseConfigAdapter) {\n            \$slug = \$adapter->get_plugin_slug();",
        "        if (\\class_exists(LicenseHandler::class) && \$adapter instanceof LicenseConfigAdapter) {\n            \$slug = \$adapter->get_plugin_slug();",
    ],
    [
        'src/AdminShell.php',
        "        new LicenseHandler(\$adapter);\n        \$info = PluginInfoFactory::from_adapter(\$adapter);",
        "        if (!\\class_exists(LicenseHandler::class)) {\n            return;\n        }\n        new LicenseHandler(\$adapter);\n        \$info = PluginInfoFactory::from_adapter(\$adapter);",
    ],
    [
        'views/licenses-page.php',
        '} elseif (!\\{NS}\\LicenseState::allows_updates($slug)) {',
        '} elseif (\\class_exists(\\{NS}\\LicenseState::class) && !\\{NS}\\LicenseState::allows_updates($slug)) {',
    ],
];

if ( ! preg_match( '/^namespace (\w+)\\\\YayCommerce\\\\AdminShell;/m', (string) file_get_contents( $dir . '/src/AdminShell.php' ), $m ) ) {
    $fail( 'src/AdminShell.php: no scoped AdminShell namespace found.' );
}
$ns = $m[1] . '\\YayCommerce\\AdminShell\\License';

foreach ( $patches as list( $file, $from, $to ) ) {
    $from   = str_replace( '{NS}', $ns, $from );
    $to     = str_replace( '{NS}', $ns, $to );
    $target = $dir . '/' . $file;
    $code   = is_file( $target ) ? (string) file_get_contents( $target ) : '';
    $hits   = substr_count( $code, $from );
    if ( 1 !== $hits ) {
        $fail( "{$file}: expected the text once, found {$hits} times:\n{$from}" );
    }
    file_put_contents( $target, str_replace( $from, $to, $code ) );
}

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
$bad   = [];
foreach ( $files as $file ) {
    if ( 'php' !== $file->getExtension() ) {
        continue;
    }
    $target = $file->getPathname();
    $short  = substr( $target, strlen( $dir ) + 1 );
    $code   = (string) file_get_contents( $target );

    // S9: every shipped file stops when loaded outside WordPress.
    if ( false === strpos( $code, 'ABSPATH' ) ) {
        $guarded = preg_replace( '/^(namespace [^;]+;\n)/m', "$1\ndefined('ABSPATH') || exit;\n", $code, 1, $count );
        if ( 1 !== $count ) {
            $fail( "{$short}: no namespace line to put the ABSPATH guard after." );
        }
        file_put_contents( $target, $guarded );
        $code = $guarded;
    }

    // A License class this file names: imported short names, the full namespace, and build_addon_adapter(), whose
    // AddonLicenseAdapter implements a License interface.
    preg_match_all( '/^use ' . preg_quote( $ns, '/' ) . '\\\\(?:\w+\\\\)*(\w+);/m', $code, $uses );
    $patterns   = array_map( fn( $name ) => '\b' . $name . '\b', $uses[1] );
    $patterns[] = preg_quote( $ns . '\\', '/' );
    $patterns[] = '->build_addon_adapter\(';
    $lines      = explode( "\n", $code );
    foreach ( $lines as $i => $line ) {
        if ( ! preg_match( '/' . implode( '|', $patterns ) . '/', $line ) ) {
            continue;
        }
        $trim = ltrim( $line );
        // Loads nothing: imports, comments, signatures, class declarations.
        if ( preg_match( '/^(use |\*|\/\/|\/\*|(public |private |protected )?(static )?function |(final )?class )/', $trim ) ) {
            continue;
        }
        $near = implode( "\n", array_slice( $lines, max( 0, $i - 4 ), min( 5, $i + 1 ) ) );
        if ( false === strpos( $near, 'class_exists(' ) ) {
            $bad[] = $short . ':' . ( $i + 1 ) . ': ' . $trim;
        }
    }
}
if ( $bad ) {
    $fail( "License classes used without a class_exists guard:\n" . implode( "\n", $bad ) );
}
echo 'Patched admin-shell for the free zip: ' . count( $patches ) . " guards, no unguarded License use.\n";
