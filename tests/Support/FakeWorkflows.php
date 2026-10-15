<?php
namespace YayMatik\Tests\Support;

use YayMatik\Workflow\Runtime\Workflows;

/** Workflows from an array. `deactivate()` turns one off and records it. */
class FakeWorkflows implements Workflows {

    /** @var array[] Id => workflow (`id`, `status`, `graph`). */
    public $workflows = [];

    /** @var int[] Id => live version id. */
    public $versions = [];

    /** @var int[] Ids turned off. */
    public $deactivated = [];

    public function active_for_trigger( string $trigger_id ): array {
        $found = [];
        foreach ( $this->workflows as $workflow ) {
            foreach ( $workflow['graph']['nodes'] as $node ) {
                if ( 'active' === $workflow['status'] && 'trigger' === $node['kind'] && $trigger_id === $node['data']['block'] ) {
                    $found[] = $workflow;
                }
            }
        }
        return $found;
    }

    public function find( int $id ): ?array {
        return $this->workflows[ $id ] ?? null;
    }

    public function live_version_id( int $id ): int {
        return $this->versions[ $id ] ?? 0;
    }

    public function deactivate( int $id ): void {
        $this->deactivated[]              = $id;
        $this->workflows[ $id ]['status'] = 'inactive';
    }
}
