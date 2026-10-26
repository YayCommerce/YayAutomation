<?php
namespace YayMatik\Workflow\Core\Runtime;

defined( 'ABSPATH' ) || exit;

/** The workflow operations of the Dispatcher and Worker, over `WorkflowStore`. */
interface Workflows {

    /**
     * @param string $trigger_id
     * @return array[]
     */
    public function active_for_trigger( string $trigger_id ): array;

    public function find( int $id ): ?array;

    /**
     * Read fresh each time, never from a cache.
     *
     * @param int $id
     */
    public function is_active( int $id ): bool;

    /**
     * 0 when the workflow has no saved version.
     *
     * @param int $id
     */
    public function live_version_id( int $id ): int;

    public function deactivate( int $id ): void;
}
