<?php
namespace YayMatik\Workflow\Runtime;

defined( 'ABSPATH' ) || exit;

/** Wakes a run up later: a job with the run id. The job only says "run the next slice"; state stays in `runs`. */
interface Queue {

    /** Whether jobs can be queued at all. Without a queue, runs go inline and can't wait or slice. */
    public function available(): bool;

    /**
     * Queues the run's next slice. No-op (true) when the run already has a pending job.
     *
     * @param int      $run_id Run id.
     * @param int|null $at     Unix time to run at; null = as soon as possible.
     * @return bool False when the job couldn't be queued.
     */
    public function enqueue( int $run_id, ?int $at = null ): bool;

    /**
     * Queues a recovery job for a run whose job died: it may take the run from `running`. No-op
     * (true) when one is already pending or in progress, so only one can take the run.
     *
     * @param int $run_id Run id.
     * @return bool False when the job couldn't be queued.
     */
    public function recover( int $run_id ): bool;
}
