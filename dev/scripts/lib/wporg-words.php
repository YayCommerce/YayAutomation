<?php
/**
 * Fails when a shipped file of the free zip names a license, a trial or an upsell, outside dev/wporg/word-allowlist.txt.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- a build script, run by php-cli without WordPress.

// Usage: php wporg-words.php <extracted plugin folder> <allow-list>
// WordPress.org reads every file that ships: a Lite file may say a feature comes with YayMatik Pro, never that a license
// or payment unlocks it, and it ships no disabled "coming soon" placeholder. Allow-list lines: `<path or file name>: "<exact phrase>": <reason>`; a match passes only inside
// one of its file's phrases (case-insensitive), so a minified bundle is checked word by word, not line by line.

const YAYMATIK_WORDS = '/licen[cs]e|\btrial|premium|unlock|upgrade to|go pro|coming soon|\bis_pro\b|licensed/i';

$root      = rtrim( $argv[1] ?? '', '/' );
$list_file = $argv[2] ?? '';
if ( ! is_dir( $root ) || ! is_file( $list_file ) ) {
    fwrite( STDERR, "Usage: php wporg-words.php <extracted plugin folder> <allow-list>\n" );
    exit( 1 );
}

$allowed = [];
foreach ( file( $list_file, FILE_IGNORE_NEW_LINES ) as $number => $line ) {
    if ( '' === trim( $line ) || '#' === $line[0] ) {
        continue;
    }
    if ( ! preg_match( '/^([^:]+): "([^"]+)": \S/', $line, $entry ) ) {
        fwrite( STDERR, "{$list_file}:" . ( $number + 1 ) . ": expected `<file>: \"<phrase>\": <reason>`\n" );
        exit( 1 );
    }
    $allowed[] = [ $entry[1], $entry[2] ];
}

// What the reviewer reads: the code, the built app, admin-shell, the readme and the translation template; not Composer's autoloader.
$files = [];
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
    $relative = substr( $file->getPathname(), strlen( $root ) + 1 );
    if ( 0 !== strpos( $relative, 'vendor/' ) ) {
        $files[] = $relative;
    }
}
sort( $files );

$found = [];
$used  = [];
foreach ( $files as $relative ) {
    $text = file_get_contents( "{$root}/{$relative}" );
    if ( ! preg_match_all( YAYMATIK_WORDS, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
        continue;
    }
    // The spans this file's allowed phrases cover.
    $spans = [];
    foreach ( $allowed as $index => list( $in_file, $phrase ) ) {
        if ( $relative !== $in_file && basename( $relative ) !== $in_file ) {
            continue;
        }
        $at = stripos( $text, $phrase );
        while ( false !== $at ) {
            $spans[] = [ $at, $at + strlen( $phrase ), $index ];
            $at      = stripos( $text, $phrase, $at + 1 );
        }
    }
    foreach ( $matches[0] as list( $word, $at ) ) {
        $covered = false;
        foreach ( $spans as list( $start, $end, $index ) ) {
            if ( $at >= $start && $at + strlen( $word ) <= $end ) {
                $covered        = true;
                $used[ $index ] = true;
                break;
            }
        }
        if ( ! $covered ) {
            $line    = substr_count( $text, "\n", 0, $at ) + 1;
            $context = preg_replace( '/\s+/', ' ', substr( $text, max( 0, $at - 40 ), strlen( $word ) + 80 ) );
            $found[] = "{$relative}:{$line}: {$word} … {$context}";
        }
    }
}

// An entry nothing needs any more is a stale exception: remove it.
foreach ( $allowed as $index => list( $in_file, $phrase ) ) {
    if ( empty( $used[ $index ] ) ) {
        $found[] = "{$list_file}: unused entry {$in_file}: \"{$phrase}\"";
    }
}

if ( $found ) {
    echo "License or upsell words in the free zip (fix the text, or add an exact phrase with its reason to {$list_file}):\n  " . implode( "\n  ", $found ) . "\n";
    exit( 1 );
}
echo 'Word scan: no license, trial or upsell words (' . count( $allowed ) . " allowed phrases).\n";
