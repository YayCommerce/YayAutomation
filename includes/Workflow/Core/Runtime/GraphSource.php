<?php
namespace YayMatik\Workflow\Core\Runtime;

defined( 'ABSPATH' ) || exit;

/** Graphs of saved versions: a run reads the version it pinned, never the live graph. */
interface GraphSource {

    /**
     * The version's graph, or null when the version is gone.
     *
     * @param int $version_id Version id.
     */
    public function graph( int $version_id ): ?array;
}
