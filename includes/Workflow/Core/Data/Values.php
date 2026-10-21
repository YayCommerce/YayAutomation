<?php
namespace YayMatik\Workflow\Core\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Where a template reads its data.
 */
interface Values {

    /**
     * Typed value at a path, or null when missing.
     *
     * @param string $path
     * @return mixed
     */
    public function get( string $path );

    /**
     * How the value reads in a message; empty when missing.
     *
     * @param string $path
     * @param bool   $html Safe HTML instead of plain text.
     */
    public function text( string $path, bool $html ): string;
}
