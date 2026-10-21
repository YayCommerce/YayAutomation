<?php
namespace YayMatik\Workflow\Runtime;

use YayMatik\Workflow\Storage\RunStepStore;
use YayMatik\Workflow\Storage\RunStore;

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

    public function steps( int $run_id ): array {
        return RunStepStore::for_run( $run_id );
    }
}
