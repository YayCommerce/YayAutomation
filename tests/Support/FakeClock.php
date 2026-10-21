<?php
namespace YayMatik\Tests\Support;

use YayMatik\Workflow\Core\Runtime\Clock;

/** A clock the test sets. `$tick` seconds pass on every elapsed() read. */
final class FakeClock implements Clock {

    /** @var int Unix time: 2026-10-12 10:00:00 UTC. */
    public $now = 1791799200;

    /** @var float */
    public $elapsed = 0.0;

    /** @var float */
    public $tick = 0.0;

    /** @var float */
    public $memory = 0.1;

    public function now(): int {
        return $this->now;
    }

    public function elapsed(): float {
        $elapsed        = $this->elapsed;
        $this->elapsed += $this->tick;
        return $elapsed;
    }

    public function memory(): float {
        return $this->memory;
    }
}
