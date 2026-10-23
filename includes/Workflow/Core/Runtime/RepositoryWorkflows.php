<?php
namespace YayMatik\Workflow\Core\Runtime;

use YayMatik\Workflow\Core\Storage\WorkflowRepository;

defined( 'ABSPATH' ) || exit;

/** `Workflows` over `WorkflowRepository`. */
final class RepositoryWorkflows implements Workflows {

    public function active_for_trigger( string $trigger_id ): array {
        return WorkflowRepository::active_for_trigger( $trigger_id );
    }

    public function find( int $id ): ?array {
        return WorkflowRepository::find( $id );
    }

    public function is_active( int $id ): bool {
        return WorkflowRepository::is_active( $id );
    }

    public function live_version_id( int $id ): int {
        return WorkflowRepository::live_version_id( $id );
    }

    public function deactivate( int $id ): void {
        if ( true === WorkflowRepository::deactivate( $id ) ) {
            Cancellation::waiting( $id, Cancellation::turned_off() );
        }
    }
}
