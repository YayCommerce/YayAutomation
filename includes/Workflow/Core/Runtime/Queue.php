<?php
namespace YayMatik\Workflow\Core\Runtime;

defined( 'ABSPATH' ) || exit;

/** Jobs that wake a run for its next slice; state stays in `runs`. */
interface Queue {

    /** Without a queue, runs go inline and can't wait or slice. */
    public function available(): bool;

    /**
     * Queues the run's next slice; no-op (true) when it already has a pending job. False when queueing failed.
     *
     * @param int      $run_id
     * @param int|null $at     Unix time; null = as soon as possible.
     */
    public function enqueue( int $run_id, ?int $at = null ): bool;

    /**
     * Queues a recovery job, which may take the run from `running`; false when queueing failed.
     *
     * @param int $run_id
     */
    public function recover( int $run_id ): bool;
}
