<?php
namespace YayMatik\Workflow\Core\Runtime;

use YayMatik\Utils\Logger;
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
        try {
            $result = WorkflowStore::locked(
                $id,
                function () use ( $id ) {
                    // A REST Deactivate may have won the lock and already cancelled the waiting runs.
                    if ( ! WorkflowStore::is_active( $id ) ) {
                        return true;
                    }
                    $done = WorkflowStore::deactivate( $id );
                    if ( true === $done ) {
                        Cancellation::waiting( $id, Cancellation::turned_off() );
                    }
                    return $done;
                }
            );
        } catch ( \Throwable $e ) {
            // Thrown on to the Dispatcher, it would skip the event's other workflows.
            Logger::error( "Workflow {$id} not turned off: " . $e->getMessage(), [ 'workflow_id' => $id ] );
            return;
        }
        if ( is_wp_error( $result ) ) {
            $reason = 'yaymatik_busy' === $result->get_error_code() ? 'could not take its lock within 10 seconds' : 'a database error, logged above';
            Logger::error( "Workflow {$id} not turned off: {$reason}.", [ 'workflow_id' => $id ] );
        }
    }
}
