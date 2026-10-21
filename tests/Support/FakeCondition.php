<?php
namespace YayMatik\Tests\Support;

use YayMatik\Workflow\Data\Scope;
use YayMatik\Workflow\Steps\Condition;

/** A condition that says yes when its config has `yes` true; `result` comes from the base class. Counts its runs. */
final class FakeCondition extends Condition {

    /** @var int */
    public $runs = 0;

    public function id(): string {
        return 'fake_condition';
    }

    public function label(): string {
        return 'Fake condition';
    }

    public function description(): string {
        return '';
    }

    public function icon(): string {
        return 'split';
    }

    public function fields(): array {
        return [];
    }

    public function summary(): string {
        return '';
    }

    /**
     * @param mixed $config Raw config.
     */
    public function sanitize( $config ): array {
        return is_array( $config ) ? $config : [];
    }

    public function evaluate( Scope $scope, array $config ): bool {
        ++$this->runs;
        return ! empty( $config['yes'] );
    }
}
