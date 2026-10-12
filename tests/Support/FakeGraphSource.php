<?php
namespace YayAutomation\Tests\Support;

use YayAutomation\Workflow\Runtime\GraphSource;

/** Version graphs from an array. */
final class FakeGraphSource implements GraphSource {

    /** @var array[] Version id => graph. */
    public $graphs = [];

    public function graph( int $version_id ): ?array {
        return $this->graphs[ $version_id ] ?? null;
    }
}
