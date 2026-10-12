<?php
namespace YayAutomation\Tests\Support;

use YayAutomation\Workflow\Runtime\Queue;

/** Records queued jobs instead of scheduling them. */
final class FakeQueue implements Queue {

    /** @var array[] [ run_id, at ]. */
    public $jobs = [];

    /** @var int[] Run ids of recovery jobs. */
    public $recoveries = [];

    /** @var bool False acts as if no queue is available. */
    public $available = true;

    /** @var bool False makes enqueue() fail while available. */
    public $succeeds = true;

    public function available(): bool {
        return $this->available;
    }

    public function enqueue( int $run_id, ?int $at = null ): bool {
        if ( ! $this->available || ! $this->succeeds ) {
            return false;
        }
        $this->jobs[] = [ $run_id, $at ];
        return true;
    }

    public function recover( int $run_id ): bool {
        $this->recoveries[] = $run_id;
        return $this->available;
    }
}
