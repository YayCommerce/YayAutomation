<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Utils\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Errors from other plugins' modules, shown in Health check › Extensions.
 *
 * @internal
 */
final class Diagnostics {

    public const OPTION = 'yaymatik_extension_errors';

    private const KEEP = 50;

    /** Seconds between two writes of the same module and callback; a literal, as PHPUnit runs without WordPress's constants. */
    private const THROTTLE = 600;

    // Repeats inside the window are counted in this request only: `count` is a lower bound.
    /** @var int[] Repeats not written yet, by `module|callback`. */
    private static $pending = [];

    /** @var array[] The first entries recorded in this request, repeats inside the window too, by `module|callback|message`; capped as a queue run can last long. */
    private static $recorded = [];

    /**
     * Never throws: callers are catch blocks.
     *
     * @param string $module
     * @param string $callback
     * @param string $message
     */
    public static function record( string $module, string $callback, string $message ): void {
        try {
            if ( count( self::$recorded ) < self::KEEP ) {
                self::$recorded[ "{$module}|{$callback}|{$message}" ] = [
                    'module'   => $module,
                    'callback' => $callback,
                    'message'  => $message,
                ];
            }
            $key     = $module . '|' . $callback;
            $entries = self::stored();
            $now     = time();
            if ( isset( $entries[ $key ] ) && $entries[ $key ]['time'] > $now - self::THROTTLE ) {
                self::$pending[ $key ] = ( self::$pending[ $key ] ?? 0 ) + 1;
                return;
            }
            // The option is autoloaded, so the throttle check above costs no query; a write reads it fresh, as another request may have written since.
            wp_cache_delete( 'alloptions', 'options' );
            $entries = self::stored();
            foreach ( self::$pending as $pending_key => $count ) {
                if ( isset( $entries[ $pending_key ] ) ) {
                    $entries[ $pending_key ]['count'] += $count;
                }
            }
            self::$pending   = [];
            $entries[ $key ] = [
                'module'   => $module,
                'callback' => $callback,
                'message'  => $message,
                'time'     => $now,
                'count'    => ( $entries[ $key ]['count'] ?? 0 ) + 1,
            ];
            uasort( $entries, fn( array $a, array $b ) => $a['time'] <=> $b['time'] );
            update_option( self::OPTION, array_slice( $entries, -self::KEEP, null, true ), true );
            Logger::error( "Extension {$module}, {$callback}: {$message}" );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
        }
    }

    /**
     * @param int $hours
     * @return array[] Newest first: `module`, `callback`, `message`, `time` (Unix), `count` (at least; repeats not written yet included).
     */
    public static function recent( int $hours ): array {
        $since   = time() - $hours * 3600;
        $entries = [];
        foreach ( self::stored() as $key => $entry ) {
            if ( $entry['time'] >= $since ) {
                $entry['count'] += self::$pending[ $key ] ?? 0;
                $entries[]       = $entry;
            }
        }
        return array_reverse( $entries );
    }

    /** @return array[] What this request recorded, each once: `module`, `callback`, `message`. */
    public static function recorded(): array {
        return array_values( self::$recorded );
    }

    /** @return array[] By `module|callback`; anything malformed is dropped. */
    private static function stored(): array {
        $entries = get_option( self::OPTION, [] );
        if ( ! is_array( $entries ) ) {
            return [];
        }
        return array_filter(
            $entries,
            fn( $entry ) => is_array( $entry ) && isset( $entry['module'], $entry['callback'], $entry['message'], $entry['time'], $entry['count'] )
        );
    }
}
