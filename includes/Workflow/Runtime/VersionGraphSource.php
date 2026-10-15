<?php
namespace YayMatik\Workflow\Runtime;

use YayMatik\Workflow\Storage\VersionStore;

defined( 'ABSPATH' ) || exit;

/** `GraphSource` over `VersionStore`. */
final class VersionGraphSource implements GraphSource {

    public function graph( int $version_id ): ?array {
        $version = VersionStore::find( $version_id );
        return $version ? $version['graph'] : null;
    }
}
