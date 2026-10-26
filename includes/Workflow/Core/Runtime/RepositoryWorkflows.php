<?php
namespace YayMatik\Workflow\Core\Runtime;

use YayMatik\Workflow\Core\Storage\WorkflowStore;

defined( 'ABSPATH' ) || exit;

/** `Workflows` over `WorkflowStore`. */
final class RepositoryWorkflows implements Workflows {

    public function active_for_trigger( string $trigger_id ): array {
        return WorkflowStore::active_for_trigger( $trigger_id );
    }

    public function find( int $id ): ?array {
        return WorkflowStore::find( $id );
    }

    public function is_active( int $id ): bool {
        return WorkflowStore::is_active( $id );
    }

    public function live_version_id( int $id ): int {
        return WorkflowStore::live_version_id( $id );
    }

    public function deactivate( int $id ): void {
        if ( true === WorkflowStore::deactivate( $id ) ) {
            Cancellation::waiting( $id, Cancellation::turned_off() );
        }
    }
}
