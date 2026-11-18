<?php
namespace YayMatik\Workflow\Core\Runtime;

defined( 'ABSPATH' ) || exit;

/** Daily jobs added beside Cleanup and CartSweep: Cleanup schedules each when missing, HealthCheck reports it late. */
final class DailyJobs {

    /** Job hook => its hooks, left scheduled with nothing to run them when the job isn't added (a store moved from YayMatik Pro). */
    public const LEFT_OVER = [ 'yaymatik_inactive_sweep' => [ 'yaymatik_inactive_sweep', 'yaymatik_inactive_sweep_batch' ] ];

    /** @var array[] Health check id => `hook`, `late` (seconds), `schedule`. */
    private static $jobs = [];

    /**
     * @param string   $check
     * @param string   $hook
     * @param int      $late
     * @param callable $schedule
     */
    public static function add( string $check, string $hook, int $late, callable $schedule ): void {
        self::$jobs[ $check ] = [
            'hook'     => $hook,
            'late'     => $late,
            'schedule' => $schedule,
        ];
    }

    /** @return array[] Keyed by health check id. */
    public static function all(): array {
        return self::$jobs;
    }
}
