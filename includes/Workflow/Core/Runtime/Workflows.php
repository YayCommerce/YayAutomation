<?php
namespace YayMatik\Workflow\Core\Runtime;

defined( 'ABSPATH' ) || exit;

/** The workflow operations of the Dispatcher and Worker, over `WorkflowRepository`. */
interface Workflows {

    /**
     * @param string $trigger_id
     * @return array[]
     */
    public function active_for_trigger( string $trigger_id ): array;

    /**
     * @param int $id
     */
    public function find( int $id ): ?array;

    /**
     * 0 when the workflow has no saved version.
     *
     * @param int $id
     */
    public function live_version_id( int $id ): int;

    /**
     * @param int $id
     */
    public function deactivate( int $id ): void;
}
