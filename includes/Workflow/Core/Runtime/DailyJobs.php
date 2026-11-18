<?php
namespace YayMatik\Workflow\Core\Runtime;

defined( 'ABSPATH' ) || exit;

/** Daily jobs added beside Cleanup and CartSweep: Cleanup schedules each when missing, HealthCheck reports it late. */
final class DailyJobs {

    /** Job hook => its hooks, left scheduled with nothing to run them when the job isn't added (a store moved from YayMatik Pro). */
    public const LEFT_OVER = [ 'yaymatik_inactive_sweep' => [ 'yaymatik_inactive_sweep', 'yaymatik_inactive_sweep_batch' ] ];

    /** @var array[] Health check id => `hook`, `late` (seconds), `schedule`, `needed` (null = always). */
    private static $jobs = [];

    /**
     * @param string        $check
     * @param string        $hook
     * @param int           $late
     * @param callable      $schedule
     * @param callable|null $needed True when the job has work now.
     */
    public static function add( string $check, string $hook, int $late, callable $schedule, ?callable $needed = null ): void {
        self::$jobs[ $check ] = [
            'hook'     => $hook,
            'late'     => $late,
            'schedule' => $schedule,
            'needed'   => $needed,
        ];
    }

    /** @return array[] Keyed by health check id. */
    public static function all(): array {
        return self::$jobs;
    }

    /** @return array[] The jobs with work now: the health check reports only these. */
    public static function needed(): array {
        return array_filter( self::$jobs, fn( array $job ) => null === $job['needed'] || ( $job['needed'] )() );
    }
}
