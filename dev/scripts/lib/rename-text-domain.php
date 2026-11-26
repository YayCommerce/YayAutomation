<?php
/**
 * Renames a gettext text domain: only the last argument of a gettext call, when it is the literal old domain.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- a build script, run by php-cli without WordPress.

// Usage: php rename-text-domain.php <from> <to> <file or dir>...
// A text replace would also rename options, hooks, the REST namespace and the Action Scheduler group, which share the word.

$gettext = [ '__', '_e', '_x', '_ex', '_n', '_nx', '_n_noop', '_nx_noop', 'esc_html__', 'esc_html_e', 'esc_html_x', 'esc_attr__', 'esc_attr_e', 'esc_attr_x' ];
$from    = $argv[1] ?? '';
$to      = $argv[2] ?? '';
$paths   = array_slice( $argv, 3 );
if ( '' === $from || '' === $to || ! $paths ) {
    fwrite( STDERR, "Usage: php rename-text-domain.php <from> <to> <file or dir>...\n" );
    exit( 1 );
}

$files = [];
foreach ( $paths as $target ) {
    if ( is_file( $target ) ) {
        $files[] = $target;
        continue;
    }
    if ( ! is_dir( $target ) ) {
        fwrite( STDERR, "Not found: {$target}\n" );
        exit( 1 );
    }
    foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $target, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
        if ( 'php' === $file->getExtension() ) {
            $files[] = $file->getPathname();
        }
    }
}
sort( $files );

$total = 0;
foreach ( $files as $file ) {
    $tokens  = token_get_all( file_get_contents( $file ) );
    $count   = count( $tokens );
    $changed = 0;
    for ( $i = 0; $i < $count; $i++ ) {
        if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || ! in_array( $tokens[ $i ][1], $gettext, true ) ) {
            continue;
        }
        // A method or a declaration of the same name isn't the WordPress function.
        $prev = $i - 1;
        while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
            --$prev;
        }
        if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], [ T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ], true ) ) {
            continue;
        }
        $open = $i + 1;
        while ( $open < $count && is_array( $tokens[ $open ] ) && T_WHITESPACE === $tokens[ $open ][0] ) {
            ++$open;
        }
        if ( '(' !== ( $tokens[ $open ] ?? null ) ) {
            continue;
        }
        // The last top-level argument: the meaningful tokens after the last top-level comma, before the closing parenthesis.
        $depth  = 0;
        $arg    = [];
        $before = [];
        for ( $j = $open + 1; $j < $count; $j++ ) {
            $text = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
            if ( in_array( $text, [ '(', '[', '{' ], true ) || ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) ) {
                ++$depth;
            } elseif ( in_array( $text, [ ')', ']', '}' ], true ) ) {
                if ( 0 === $depth ) {
                    break;
                }
                --$depth;
            } elseif ( ',' === $text && 0 === $depth ) {
                $before = $arg;
                $arg    = [];
                continue;
            }
            if ( ! is_array( $tokens[ $j ] ) || ! in_array( $tokens[ $j ][0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
                $arg[] = $j;
            }
        }
        // A trailing comma leaves the last argument empty.
        $arg = $arg ? $arg : $before;
        if ( 1 === count( $arg ) && is_array( $tokens[ $arg[0] ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $arg[0] ][0] ) {
            $literal = $tokens[ $arg[0] ][1];
            if ( substr( $literal, 1, -1 ) === $from ) {
                $tokens[ $arg[0] ][1] = $literal[0] . $to . $literal[0];
                ++$changed;
            }
        }
    }
    if ( $changed ) {
        file_put_contents( $file, implode( '', array_map( fn( $token ) => is_array( $token ) ? $token[1] : $token, $tokens ) ) );
        echo "{$file}: {$changed}\n";
        $total += $changed;
    }
}
echo "Renamed {$total} text domain argument(s) in " . count( $files ) . " file(s) scanned.\n";
