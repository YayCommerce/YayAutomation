<?php
namespace YayAutomation\Workflow\Runtime;

use YayAutomation\Workflow\Storage\WorkflowRepository;

defined( 'ABSPATH' ) || exit;

/** `Workflows` over `WorkflowRepository`. */
final class RepositoryWorkflows implements Workflows {

    public function active_for_trigger( string $trigger_id ): array {
        return WorkflowRepository::active_for_trigger( $trigger_id );
    }

    public function find( int $id ): ?array {
        return WorkflowRepository::find( $id );
    }

    public function live_version_id( int $id ): int {
        if ( ! WorkflowRepository::has_versions( $id ) ) {
            WorkflowRepository::seed_versions( $id );
        }
        return WorkflowRepository::live_version_id( $id );
    }

    public function deactivate( int $id ): void {
        WorkflowRepository::deactivate( $id );
    }
}
