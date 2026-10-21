<?php
namespace YayMatik\Workflow\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * The `runs` and `run_steps` operations the Dispatcher, Worker and Executor use. Row shapes and
 * statuses are those of `RunStore` and `RunStepStore`. An interface so tests can use a fake.
 */
interface RunRepository {

    /**
     * A queued run. See `RunStore::create()`.
     *
     * @param array $run Run fields.
     * @return int Run id; 0 when it couldn't be saved.
     */
    public function create_run( array $run ): int;

    /**
     * A run with `position` and `scope` decoded, or null.
     *
     * @param int $id Run id.
     */
    public function find_run( int $id ): ?array;

    /**
     * Moves a run to `running` only if it is still in one of `$from`.
     *
     * @param int      $id   Run id.
     * @param string[] $from Statuses it may be taken from.
     */
    public function claim_run( int $id, array $from ): bool;

    /**
     * Saves the given run fields. See `RunStore::save()`.
     *
     * @param int   $id     Run id.
     * @param array $fields `position`, `scope`, `status`, `wait_until`, `error`.
     */
    public function save_run( int $id, array $fields ): bool;

    /**
     * Runs of a workflow started in the last `$seconds`. See `RunStore::recent_counts()`.
     *
     * @param int $workflow_id Workflow id.
     * @param int $seconds     Window.
     * @return int[] `all`, `chained` (depth > 0).
     */
    public function recent_counts( int $workflow_id, int $seconds ): array;

    /**
     * A `running` step row. See `RunStepStore::start()`.
     *
     * @param int    $run_id  Run id.
     * @param string $step_id Step id.
     * @param array  $input   Config, merge tags unrendered.
     * @return int Row id; 0 when it couldn't be saved.
     */
    public function start_step( int $run_id, string $step_id, array $input ): int;

    /**
     * A step's result. See `RunStepStore::finish()`.
     *
     * @param int         $id     Row id.
     * @param string      $status Step status.
     * @param array       $output Declared outputs.
     * @param string|null $error  Reason.
     */
    public function finish_step( int $id, string $status, array $output = [], ?string $error = null ): bool;

    /**
     * A run's step rows in the order they ran.
     *
     * @param int $run_id Run id.
     * @return array[]
     */
    public function steps( int $run_id ): array;
}
