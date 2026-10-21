<?php
namespace YayMatik\Workflow\Core\Runtime;

defined( 'ABSPATH' ) || exit;

/** The `runs` and `run_steps` operations of the runtime, over `RunStore` and `RunStepStore`. */
interface RunRepository {

    /**
     * @param array $run
     * @return int Run id; 0 when it couldn't be saved.
     */
    public function create_run( array $run ): int;

    /**
     * @param int $id
     */
    public function find_run( int $id ): ?array;

    /**
     * @param int      $id
     * @param string[] $from Statuses it may be taken from.
     */
    public function claim_run( int $id, array $from ): bool;

    /**
     * @param int   $id
     * @param array $fields `position`, `scope`, `status`, `wait_until`, `error`.
     */
    public function save_run( int $id, array $fields ): bool;

    /**
     * @param int $workflow_id
     * @param int $seconds
     * @return int[] `all`, `chained`.
     */
    public function recent_counts( int $workflow_id, int $seconds ): array;

    /**
     * @param int    $run_id
     * @param string $step_id
     * @param array  $input
     * @return int Row id; 0 when it couldn't be saved.
     */
    public function start_step( int $run_id, string $step_id, array $input ): int;

    /**
     * @param int         $id
     * @param string      $status
     * @param array       $output
     * @param string|null $error
     */
    public function finish_step( int $id, string $status, array $output = [], ?string $error = null ): bool;

    /**
     * @param int $run_id
     * @return array[] In the order they ran.
     */
    public function steps( int $run_id ): array;
}
