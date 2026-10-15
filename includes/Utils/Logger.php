<?php
namespace YayMatik\Utils;

defined( 'ABSPATH' ) || exit;

/** Writes to WooCommerce → Status → Logs (source `yaymatik`), or the PHP error log without WooCommerce. */
final class Logger {

    public const SOURCE = 'yaymatik';

    /**
     * Never throws: callers use it inside catch blocks that must not fail.
     *
     * @param string $message Log line.
     * @param array  $context Extra data, e.g. workflow and order ids.
     */
    public static function error( string $message, array $context = [] ) {
        try {
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error( $message, array_merge( $context, [ 'source' => self::SOURCE ] ) );
                return;
            }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r
            error_log( '[' . self::SOURCE . '] ' . $message . ( $context ? ' ' . print_r( $context, true ) : '' ) );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
        }
    }
}
