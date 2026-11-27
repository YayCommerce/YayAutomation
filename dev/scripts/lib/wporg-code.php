<?php
/**
 * WordPress.org code gates on an extracted free zip: edition limits, request handlers without a nonce, the installer.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- a build script, run by php-cli without WordPress.

// Usage: php wporg-code.php <extracted plugin folder> <nonce exceptions> <edition allow-list>
// Both lists hold `<path in the zip>::<function> — <reason>`; a closure is `{closure}`, code outside a function `{file}`.
// An entry nothing matches fails too: an exception outlives its reason.

$root = rtrim( $argv[1] ?? '', '/' );
if ( ! is_dir( $root ) || ! is_file( $argv[2] ?? '' ) || ! is_file( $argv[3] ?? '' ) ) {
    fwrite( STDERR, "Usage: php wporg-code.php <extracted plugin folder> <nonce exceptions> <edition allow-list>\n" );
    exit( 1 );
}
$read_list        = function ( string $file ): array {
    $entries = [];
    foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $number => $line ) {
        if ( '' === trim( $line ) || '#' === $line[0] ) {
            continue;
        }
        if ( ! preg_match( '/^(\S+::\S+) — \S/u', $line, $entry ) ) {
            fwrite( STDERR, "{$file}:" . ( $number + 1 ) . ": expected `<path>::<function> — <reason>`\n" );
            exit( 1 );
        }
        $entries[ $entry[1] ] = false;
    }
    return $entries;
};
$nonce_exceptions = $read_list( $argv[2] );
$edition_allowed  = $read_list( $argv[3] );

/**
 * The functions of a file: [ name, first line, last line, tokens of the body ]. Innermost first when nested.
 *
 * @param array $tokens
 */
function yaymatik_functions( array $tokens ): array {
    $found = [];
    $count = count( $tokens );
    for ( $i = 0; $i < $count; $i++ ) {
        if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
            continue;
        }
        $name = '{closure}';
        for ( $j = $i + 1; $j < $count && '(' !== $tokens[ $j ]; $j++ ) {
            if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
                $name = $tokens[ $j ][1];
            }
        }
        // The body: the first `{` after the signature, unless a `;` ends it first (abstract or interface method).
        for ( ; $j < $count && '{' !== $tokens[ $j ] && ';' !== $tokens[ $j ]; $j++ ) {
            continue;
        }
        if ( ';' === ( $tokens[ $j ] ?? ';' ) ) {
            continue;
        }
        $depth = 0;
        for ( $k = $j; $k < $count; $k++ ) {
            $text = is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];
            if ( '{' === $text || ( is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) ) {
                ++$depth;
            } elseif ( '}' === $text && 0 === --$depth ) {
                break;
            }
        }
        $first   = $tokens[ $i ][2];
        $last    = $first + substr_count( implode( '', array_map( fn( $t ) => is_array( $t ) ? $t[1] : $t, array_slice( $tokens, $i, $k - $i + 1 ) ) ), "\n" );
        $found[] = [ $name, $first, $last, array_slice( $tokens, $j, $k - $j + 1 ) ];
    }
    // Innermost first: the narrowest span that holds a line.
    usort( $found, fn( $a, $b ) => ( $a[2] - $a[1] ) <=> ( $b[2] - $b[1] ) );
    return $found;
}

/**
 * Whether the string at `$at` is the first argument of add_action() / add_filter() (not has_action() or remove_action()).
 *
 * @param array $tokens
 * @param int   $at
 */
function yaymatik_hooked_by_add( array $tokens, int $at ): bool {
    $before = [];
    for ( $i = $at - 1; $i >= 0 && ! isset( $before[1] ); $i-- ) {
        if ( ! is_array( $tokens[ $i ] ) || T_WHITESPACE !== $tokens[ $i ][0] ) {
            $before[] = $tokens[ $i ];
        }
    }
    return 2 === count( $before ) && '(' === $before[0] && is_array( $before[1] )
        && in_array( strtolower( ltrim( $before[1][1], '\\' ) ), [ 'add_action', 'add_filter' ], true );
}

/**
 * @param array $functions
 * @param int   $line
 */
function yaymatik_function_at( array $functions, int $line ): ?array {
    foreach ( $functions as $function ) {
        if ( $line >= $function[1] && $line <= $function[2] ) {
            return $function;
        }
    }
    return null;
}

/**
 * @param array $span
 */
function yaymatik_checks_nonce( array $span ): bool {
    foreach ( $span[3] as $token ) {
        if ( is_array( $token ) && in_array( strtolower( ltrim( $token[1], '\\' ) ), [ 'check_ajax_referer', 'wp_verify_nonce', 'check_admin_referer' ], true ) ) {
            return true;
        }
    }
    return false;
}

$files = [];
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
    $relative = substr( $file->getPathname(), strlen( $root ) + 1 );
    if ( 'php' === $file->getExtension() && 0 !== strpos( $relative, 'vendor/' ) ) {
        $files[] = $relative;
    }
}
sort( $files );

$found     = [];
$installer = [];
foreach ( $files as $relative ) {
    $code      = (string) file_get_contents( "{$root}/{$relative}" );
    $tokens    = token_get_all( $code );
    $functions = yaymatik_functions( $tokens );
    $name_at   = function ( int $line ) use ( $functions, $relative ): string {
        $function = yaymatik_function_at( $functions, $line );
        return $relative . '::' . ( $function ? $function[0] : '{file}' );
    };

    // Gate 4: request input, AJAX / admin-post hooks and NonceVerification ignores, without a nonce check in their function.
    $needs_nonce = [];
    foreach ( $tokens as $i => $token ) {
        if ( ! is_array( $token ) ) {
            continue;
        }
        if ( T_VARIABLE === $token[0] && in_array( $token[1], [ '$_GET', '$_POST', '$_REQUEST' ], true ) ) {
            $needs_nonce[ $token[2] ] = yaymatik_function_at( $functions, $token[2] );
        } elseif ( in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) && false !== strpos( $token[1], 'NonceVerification' ) ) {
            $needs_nonce[ $token[2] ] = yaymatik_function_at( $functions, $token[2] );
        } elseif ( T_CONSTANT_ENCAPSED_STRING === $token[0] && preg_match( '/^[\'"]((wp_ajax_|wc_ajax_|admin_post_)[^\'"]*)/', $token[1], $hook ) && yaymatik_hooked_by_add( $tokens, $i ) ) {
            // The callback on the same statement: `[ $this, 'method' ]`, `array( self::class, 'method' )`, or a closure.
            $statement = '';
            for ( $j = $i; isset( $tokens[ $j ] ) && ';' !== $tokens[ $j ]; $j++ ) {
                $statement .= is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
                if ( is_array( $tokens[ $j ] ) && T_FUNCTION === $tokens[ $j ][0] ) {
                    $statement .= '{closure on line ' . $tokens[ $j ][2] . '}';
                }
            }
            $callback = null;
            if ( preg_match( '/\{closure on line (\d+)\}/', $statement, $m ) ) {
                $callback = yaymatik_function_at( $functions, (int) $m[1] );
            } elseif ( preg_match( '/,\s*(?:\[|array\s*\()\s*[^,\])]+,\s*[\'"](\w+)[\'"]\s*[\])]/', $statement, $m ) ) {
                foreach ( $functions as $function ) {
                    $callback = $m[1] === $function[0] ? $function : $callback;
                }
            }
            $needs_nonce[ $token[2] ] = $callback ? $callback : [ '{hook:' . $hook[1] . '}', 0, 0, [] ];
        }
    }
    $closures = [];
    foreach ( $needs_nonce as $line => $function ) {
        $key = $relative . '::' . ( $function ? $function[0] : '{file}' );
        if ( array_key_exists( $key, $nonce_exceptions ) ) {
            $nonce_exceptions[ $key ] = true;
            // One `{closure}` entry stands for one closure: a second one in the file needs its own reason (name it).
            if ( '{closure}' === $function[0] ) {
                $closures[ $function[1] ] = true;
                if ( count( $closures ) > 1 ) {
                    $found[ "closures {$key}" ] = "{$key}: two closures read request input under one exception; make the new one a named function.";
                }
            }
        } elseif ( ! $function || ! yaymatik_checks_nonce( $function ) ) {
            $found[ "nonce {$key}" ] = "Request handler without a nonce check: {$key} (line {$line}). Add the check, or the reason to dev/wporg/nonce-exceptions.txt.";
        }
    }

    // Gate 3: what could make the free zip's features depend on an edition or a license.
    if ( 0 === strpos( $relative, 'includes/' ) ) {
        foreach ( $tokens as $i => $token ) {
            if ( ! is_array( $token ) ) {
                continue;
            }
            $hit = '';
            if ( T_STRING === $token[0] && preg_match( '/^(is_)?licensed$/i', $token[1] ) ) {
                $hit = $token[1];
            } elseif ( 'class_exists' === strtolower( ltrim( $token[1], '\\' ) ) && in_array( $token[0], [ T_STRING, T_NAME_FULLY_QUALIFIED ], true ) ) {
                $call = '';
                for ( $j = $i; isset( $tokens[ $j ] ) && ')' !== $tokens[ $j ]; $j++ ) {
                    $call .= is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
                }
                $hit = false !== strpos( $call, '\\Pro\\' ) ? trim( $call ) . ')' : '';
            } elseif ( T_FUNCTION === $token[0] ) {
                // A method that widens a static table at run time: limits that another build lifts.
                $function = yaymatik_function_at( $functions, $token[2] );
                $body     = $function ? implode( '', array_map( fn( $t ) => is_array( $t ) ? $t[1] : $t, $function[3] ) ) : '';
                $hit      = $function && preg_match( '/^(allow|extend|widen)/i', $function[0] ) && preg_match( '/\b(self|static)::\$\w+\s*(\[[^\]]*\]\s*)?[.+]?=[^=]/', $body )
                    ? 'function ' . $function[0] . ' changes a static property' : '';
            }
            if ( '' === $hit ) {
                continue;
            }
            $key = $name_at( $token[2] );
            if ( array_key_exists( $key, $edition_allowed ) ) {
                $edition_allowed[ $key ] = true;
            } else {
                $found[ "edition {$key} {$hit}" ] = "Edition-dependent code: {$key} (line {$token[2]}): {$hit}. Remove it, or add the reason to dev/wporg/edition-allowlist.txt.";
            }
        }
    }

    // Gate 5: the plugin installer lives only in the hardened Recommended Plugins page.
    if ( preg_match( '/\bPlugin_Upgrader\b|->install\(|\bactivate_plugin\(/', $code ) ) {
        $installer[] = $relative;
    }
}

$installer_page = 'vendor-prefixed/src/Pages/RecommendedPluginsPage.php';
foreach ( $installer as $relative ) {
    if ( $installer_page !== $relative ) {
        $found[] = "Plugin installer code outside {$installer_page}: {$relative}";
    }
}
if ( in_array( $installer_page, $installer, true ) && false === strpos( (string) file_get_contents( "{$root}/{$installer_page}" ), 'function is_listed_plugin_file(' ) ) {
    $found[] = "{$installer_page}: installs without the list check (is_listed_plugin_file, dev/scripts/lib/free-admin-shell.php)";
}

foreach ( [
    'dev/wporg/nonce-exceptions.txt'  => $nonce_exceptions,
    'dev/wporg/edition-allowlist.txt' => $edition_allowed,
] as $list => $entries ) {
    foreach ( array_keys( array_filter( $entries, fn( $used ) => ! $used ) ) as $key ) {
        $found[] = "{$list}: unused entry {$key}";
    }
}

if ( $found ) {
    echo "WordPress.org code gates:\n  " . implode( "\n  ", $found ) . "\n";
    exit( 1 );
}
echo 'Code gates: no edition limits, every request handler checks a nonce (' . count( $nonce_exceptions ) . ' written exceptions), installer only in the Recommended Plugins page.' . "\n";
