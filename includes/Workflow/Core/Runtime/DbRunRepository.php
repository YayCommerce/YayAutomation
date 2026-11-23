<?php
namespace YayMatik\Workflow\Core\Runtime;

use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\TriggerClaimStore;

defined( 'ABSPATH' ) || exit;

/** `RunRepository` over the plugin's tables. */
final class DbRunRepository implements RunRepository {

    public function create_run( array $run ): int {
        return RunStore::create( $run );
    }

    public function find_run( int $id ): ?array {
        return RunStore::find( $id );
    }

    public function claim_run( int $id, array $from ): bool {
        return RunStore::claim( $id, $from );
    }

    public function save_run( int $id, array $fields ): bool {
        return RunStore::save( $id, $fields );
    }

    public function recent_counts( int $workflow_id, int $seconds ): array {
        return RunStore::recent_counts( $workflow_id, $seconds );
    }

    public function start_step( int $run_id, string $step_id, array $input ): int {
        return RunStepStore::start( $run_id, $step_id, $input );
    }

    public function finish_step( int $id, string $status, array $output = [], ?string $error = null ): bool {
        return RunStepStore::finish( $id, $status, $output, $error );
    }

    public function close_steps( int $run_id, string $status, string $error ): bool {
        return RunStepStore::close_open( $run_id, $status, $error );
    }

    public function steps( int $run_id ): array {
        return RunStepStore::for_run( $run_id );
    }

    public function claim_subject( int $workflow_id, string $subject, int $cooldown = 0 ): bool {
        return TriggerClaimStore::claim( $workflow_id, $subject, $cooldown );
    }

    public function release_subject( int $workflow_id, string $subject ): void {
        TriggerClaimStore::release( $workflow_id, $subject );
    }
}
