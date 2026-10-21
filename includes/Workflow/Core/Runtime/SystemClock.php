<?php
namespace YayMatik\Workflow\Core\Runtime;

defined( 'ABSPATH' ) || exit;

/** The real clock. Create one per slice: `elapsed()` counts from construction. */
final class SystemClock implements Clock {

    /** @var float */
    private $start;

    public function __construct() {
        $this->start = microtime( true );
    }

    public function now(): int {
        return time();
    }

    public function elapsed(): float {
        return microtime( true ) - $this->start;
    }

    public function memory(): float {
        $limit = self::bytes( (string) ini_get( 'memory_limit' ) );
        return $limit > 0 ? memory_get_usage( true ) / $limit : 0.0;
    }

    /**
     * `128M` → bytes; -1 (no limit) → 0.
     *
     * @param string $value
     */
    private static function bytes( string $value ): int {
        $value  = trim( $value );
        $number = (int) $value;
        if ( $number <= 0 ) {
            return 0;
        }
        $units = [
            'g' => 1073741824,
            'm' => 1048576,
            'k' => 1024,
        ];
        return $number * ( $units[ strtolower( substr( $value, -1 ) ) ] ?? 1 );
    }
}
