<?php
namespace YayMatik\Tests\Support;

use YayMatik\Workflow\Runtime\GraphSource;

/** Version graphs from an array. */
final class FakeGraphSource implements GraphSource {

    /** @var array[] Version id => graph. */
    public $graphs = [];

    public function graph( int $version_id ): ?array {
        return $this->graphs[ $version_id ] ?? null;
    }
}
