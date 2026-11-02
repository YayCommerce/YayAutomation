<?php
namespace YayMatik\Tests\Support;

use YayMatik\Workflow\Core\Steps\Trigger;

/** A trigger whose `stop_reason()` a test sets. */
final class FakeTrigger extends Trigger {

    /** @var string|null */
    public $reason = null;

    public function id(): string {
        return 'fake_trigger';
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

    public function listen( callable $emit ): void {}

    public function context( array $args ): ?array {
        return [];
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
}
