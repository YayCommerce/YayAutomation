<?php
/**
 * Patches the scoped admin-shell in a build folder; fails with file and line when the copy doesn't match.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- a build script, run by php-cli without WordPress.

// Every edition: each shipped file stops when it is requested directly. The free zip also leaves out src/License (no
// license client or updater on WordPress.org); the rest of admin-shell still names those classes on paths that run on
// any site with another YayCommerce plugin, so each use gets a class_exists guard here, and the copy is checked: an
// admin-shell update that moves or adds a use fails the build.
// Usage: php free-admin-shell.php <build>/vendor-prefixed [--abspath-only]

$dir           = rtrim( $argv[1] ?? '', '/' );
$abspath_only  = in_array( '--abspath-only', $argv, true );
$fail          = function ( string $message ) {
    fwrite( STDERR, "admin-shell build patch: {$message}\n" );
    exit( 1 );
};
$php_files     = function () use ( $dir ) {
    $found = [];
    foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
        if ( 'php' === $file->getExtension() ) {
            $found[ substr( $file->getPathname(), strlen( $dir ) + 1 ) ] = $file->getPathname();
        }
    }
    ksort( $found );
    return $found;
};
$abspath_guard = '/defined\(\s*[\'"]ABSPATH[\'"]\s*\)/';

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
if ( ! preg_match( '/^namespace (\w+)\\\\YayCommerce\\\\AdminShell;/m', (string) file_get_contents( $dir . '/src/AdminShell.php' ), $m ) ) {
    $fail( 'src/AdminShell.php: no scoped AdminShell namespace found.' );
}
$ns = $m[1] . '\\YayCommerce\\AdminShell\\License';

// LicenseState::is_past_expiry() is a date check; without the class the same check runs inline, so an expired key
// still shows as expired.
$past_expiry = '(static function ($e) { return \\is_string($e) && \'\' !== $e && \'lifetime\' !== $e && \'Not updated\' !== $e && \\strtotime($e) < \\time(); })';

// [ file, exact text, replacement ]. `{NS}` is the scoped License namespace; `{PAST}` the inline expiry check.
$patches = [
    [
        'src/Registry/LegacyBridge.php',
        "return LicenseState::is_past_expiry(\$raw_info['expires'] ?? null) ? 'expired' : 'valid';",
        "return (\\class_exists(LicenseState::class) ? LicenseState::is_past_expiry(\$raw_info['expires'] ?? null) : {PAST}(\$raw_info['expires'] ?? null)) ? 'expired' : 'valid';",
    ],
    [
        'src/Registry/AddonBridge.php',
        "return LicenseState::is_past_expiry(\$raw_info['expires'] ?? null) ? 'expired' : 'valid';",
        "return (\\class_exists(LicenseState::class) ? LicenseState::is_past_expiry(\$raw_info['expires'] ?? null) : {PAST}(\$raw_info['expires'] ?? null)) ? 'expired' : 'valid';",
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
        // Not allows_updates() inline: in this branch the key and info exist, so "no updates" means revoked or expired.
        'views/licenses-page.php',
        '} elseif (!\\{NS}\\LicenseState::allows_updates($slug)) {',
        '} elseif (\\class_exists(\\{NS}\\LicenseState::class) ? !\\{NS}\\LicenseState::allows_updates($slug) : (static function ($i) { $i = \\is_string($i) ? (\\json_decode($i, \\true) ?: []) : (array) $i; return \'disabled\' === ($i[\'license\'] ?? \'valid\') || {PAST}($i[\'expires\'] ?? null); })($info)) {',
    ],
];

foreach ( $patches as list( $file, $from, $to ) ) {
    $from   = str_replace( '{NS}', $ns, $from );
    $to     = str_replace( [ '{NS}', '{PAST}' ], [ $ns, $past_expiry ], $to );
    $target = $dir . '/' . $file;
    $code   = is_file( $target ) ? (string) file_get_contents( $target ) : '';
    $hits   = substr_count( $code, $from );
    if ( 1 !== $hits ) {
        $fail( "{$file}: expected the text once, found {$hits} times:\n{$from}" );
    }
    file_put_contents( $target, str_replace( $from, $to, $code ) );
}

// What loading would need src/License: its classes by full, quoted, relative or imported (also aliased) name; classes
// that implement or extend one (AddonLicenseAdapter); and methods that return such a class (build_addon_adapter()).
$sources   = array_map( 'file_get_contents', $php_files() );
$ns_any    = preg_quote( $ns, '/' ) . '|' . preg_quote( str_replace( '\\', '\\\\', $ns ), '/' ) . '|(?<![\\\\\w])License\\\\\w';
$imports   = function ( string $code ) use ( $ns ) {
    preg_match_all( '/^use ' . preg_quote( $ns, '/' ) . '\\\\(?:\w+\\\\)*(\w+)(?: as (\w+))?;/m', $code, $found, PREG_SET_ORDER );
    return array_map( fn( $found_use ) => $found_use[2] ?? $found_use[1], $found );
};
$dependent = [];
$returning = [];
foreach ( $sources as $code ) {
    $imported = $imports( $code );
    $named    = $imported ? '(?:' . implode( '|', $imported ) . ')\b|' . $ns_any : $ns_any;
    if ( preg_match_all( '/^\s*(?:final |abstract )?class (\w+)[^{]*\b(?:implements|extends)\b[^{]*(?:' . $named . ')/m', $code, $classes ) ) {
        $dependent = array_merge( $dependent, $classes[1] );
    }
}
foreach ( $sources as $code ) {
    if ( $dependent && preg_match_all( '/function (\w+)\([^)]*\)\s*:\s*\??\\\\?(?:\w+\\\\)*(?:' . implode( '|', $dependent ) . ')\b/', $code, $methods ) ) {
        $returning = array_merge( $returning, $methods[1] );
    }
}

$bad = [];
foreach ( $sources as $short => $code ) {
    $imported = $imports( $code );
    $patterns = array_merge( array_map( fn( $name ) => '\b' . $name . '\b', array_merge( $imported, $dependent ) ), [ $ns_any ] );
    foreach ( $returning as $method ) {
        $patterns[] = '(?:->|::)' . $method . '\(';
    }
    // A guard counts only when it asks for a License class.
    $guard = '/class_exists\(\s*\\\\?(?:[\w\\\\]*\\\\)?(?:' . implode( '|', array_merge( $imported, [ 'License\w*', 'RestAPI', 'PluginInfoFactory' ] ) ) . ')::class/';
    $lines = explode( "\n", $code );
    // Inside a method that returns a dependent class: its callers are checked instead.
    $in_returning = false;
    foreach ( $lines as $i => $line ) {
        if ( preg_match( '/function (\w+)\(/', $line, $fn ) ) {
            $in_returning = in_array( $fn[1], $returning, true );
        }
        if ( $in_returning || ! preg_match( '/' . implode( '|', $patterns ) . '/', $line ) ) {
            continue;
        }
        $trim = ltrim( $line );
        // Loads nothing: imports, comments, signatures, class declarations.
        if ( preg_match( '/^(use |\*|\/\/|\/\*|(public |private |protected )?(static )?function |(final |abstract )?class |interface )/', $trim ) ) {
            continue;
        }
        if ( ! preg_match( $guard, implode( "\n", array_slice( $lines, max( 0, $i - 4 ), min( 5, $i + 1 ) ) ) ) ) {
            $bad[] = $short . ':' . ( $i + 1 ) . ': ' . $trim;
        }
    }
}
if ( $bad ) {
    $fail( "License classes used without a class_exists guard:\n" . implode( "\n", $bad ) );
}
echo 'admin-shell: ABSPATH guard in every file; ' . count( $patches ) . " License guards, no unguarded use.\n";
