<?php
namespace YayMatik\Workflow\Runtime;

defined( 'ABSPATH' ) || exit;

/** Time and memory for one slice, so the Executor knows when to stop. */
interface Clock {

    /** Unix time. */
    public function now(): int;

    /** Seconds since the slice started. */
    public function elapsed(): float;

    /** Share of the PHP memory limit in use, 0–1; 0 when there is no limit. */
    public function memory(): float;
}
