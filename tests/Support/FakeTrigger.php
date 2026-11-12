<?php
namespace YayMatik\Tests\Support;

use YayMatik\Workflow\Core\Steps\Trigger;

/** A trigger whose id, context, `claim()`, `skip_reason()` and `stop_reason()` a test sets; counts its `listen()` calls. */
final class FakeTrigger extends Trigger {

    /** @var string|null */
    public $reason = null;

    /** @var bool */
    public $claims = true;

    /** @var string|null */
    public $skip = null;

    /** @var array What `context()` returns; empty reads as no context. */
    public $event_context = [];

    /** @var string */
    public $trigger_id;

    /** @var int */
    public $listens = 0;

    /** @param string $trigger_id */
    public function __construct( string $trigger_id = 'fake_trigger' ) {
        $this->trigger_id = $trigger_id;
    }

    public function id(): string {
        return $this->trigger_id;
    }

    public function label(): string {
        return 'Fake trigger';
    }

    public function description(): string {
        return '';
    }

    public function icon(): string {
        return 'mail';
    }

    public function fields(): array {
        return [];
    }

    public function summary(): string {
        return '';
    }

    public function provides(): array {
        return [];
    }

    public function listen( callable $emit ): void {
        ++$this->listens;
    }

    public function context( array $args ): ?array {
        return $this->event_context;
    }

    public function refs( array $args ): ?array {
        return [];
    }

    public function subject( array $args ): string {
        return '';
    }

    public function stop_reason( string $subject ): ?string {
        return $this->reason;
    }

    public function claim( array $args ): bool {
        return $this->claims;
    }

    public function skip_reason( array $args ): ?string {
        return $this->skip;
    }
}
