<?php
namespace YayMatik\Workflow\Core\Runtime;

defined( 'ABSPATH' ) || exit;

/** The workflow operations the Dispatcher and Worker use. An interface so tests can use a fake. */
interface Workflows {

    /**
     * Active workflows whose trigger is the given step, shaped like `WorkflowRepository::find()`.
     *
     * @param string $trigger_id Trigger step id.
     * @return array[]
     */
    public function active_for_trigger( string $trigger_id ): array;

    /**
     * A workflow, or null.
     *
     * @param int $id Workflow id.
     */
    public function find( int $id ): ?array;

    /**
     * The live version id, seeding versions first for a workflow saved before they existed. 0 when
     * there is still none.
     *
     * @param int $id Workflow id.
     */
    public function live_version_id( int $id ): int;

    /**
     * Turns a workflow off.
     *
     * @param int $id Workflow id.
     */
    public function deactivate( int $id ): void;
}
