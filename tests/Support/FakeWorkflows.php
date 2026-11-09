<?php
namespace YayMatik\Tests\Support;

use YayMatik\Workflow\Core\Runtime\Workflows;

/** Workflows from an array. `deactivate()` turns one off and records it. */
class FakeWorkflows implements Workflows {

    /** @var array[] Id => workflow (`id`, `status`, `graph`). */
    public $workflows = [];

    /** @var int[] Id => live version id. */
    public $versions = [];

    /** @var int[] Ids turned off. */
    public $deactivated = [];

    /** @var int Calls to `active_for_trigger()`. */
    public $lookups = 0;

    /** @var bool `active_trigger_ids()` reads as a query error. */
    public $list_fails = false;

    public function active_trigger_ids(): ?array {
        if ( $this->list_fails ) {
            return null;
        }
        $ids = [];
        foreach ( $this->workflows as $workflow ) {
            foreach ( $workflow['graph']['nodes'] as $node ) {
                if ( 'active' === $workflow['status'] && 'trigger' === $node['kind'] ) {
                    $ids[] = $node['data']['block'];
                }
            }
        }
        return array_values( array_unique( $ids ) );
    }

    public function active_for_trigger( string $trigger_id ): array {
        ++$this->lookups;
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

    public function is_active( int $id ): bool {
        return 'active' === ( $this->workflows[ $id ]['status'] ?? '' );
    }

    public function live_version_id( int $id ): int {
        return $this->versions[ $id ] ?? 0;
    }

    public function deactivate( int $id ): void {
        $this->deactivated[]              = $id;
        $this->workflows[ $id ]['status'] = 'inactive';
    }
}
