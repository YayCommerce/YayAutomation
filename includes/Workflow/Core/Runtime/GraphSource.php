<?php
namespace YayMatik\Workflow\Core\Runtime;

defined( 'ABSPATH' ) || exit;

/** Graphs of saved versions: a run reads the version it pinned, never the live graph. */
interface GraphSource {

    /**
     * Null when the version is gone.
     *
     * @param int $version_id
     */
    public function graph( int $version_id ): ?array;
}
