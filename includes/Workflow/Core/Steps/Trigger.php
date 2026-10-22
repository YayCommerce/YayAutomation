<?php
namespace YayMatik\Workflow\Core\Steps;

use YayMatik\Workflow\Core\Data\Scope;

defined( 'ABSPATH' ) || exit;

/**
 * The step that starts a workflow: it hooks its own events and reports each one.
 */
abstract class Trigger extends Step {

    public function kind(): string {
        return self::KIND_TRIGGER;
    }

    /** Data items every run context of this trigger holds. */
    abstract public function provides(): array;

    /**
     * Hooks the events; calls `$emit( $args )` once per event.
     *
     * @param callable $emit fn( array $args ): void. Args must survive JSON (they go through Action Scheduler).
     */
    abstract public function listen( callable $emit ): void;

    /**
     * Run context for an event, or null when its subject is gone.
     *
     * @param array $args
     */
    abstract public function context( array $args ): ?array;

    /**
     * The run's `trigger` scope section, or null when the subject is gone.
     *
     * @param array $args
     * @return array|null Key => Ref or plain value (a fact only true at trigger time); never whole objects.
     */
    abstract public function refs( array $args ): ?array;

    /**
     * What the event is about, e.g. `order:12`.
     *
     * @param array $args
     */
    abstract public function subject( array $args ): string;

    /**
     * Whether a workflow with this config runs for the event.
     *
     * @param array $context
     * @param array $config
     */
    public function matches( array $context, array $config ): bool {
        return true;
    }

    /**
     * Last check before the event's workflows are queued, once per event.
     *
     * @param array $args
     */
    public function claim( array $args ): bool {
        return true;
    }

    /**
     * Nothing to do: what the trigger supplies is already in the Scope.
     *
     * @param Scope $scope
     * @param array $config
     */
    public function run( Scope $scope, array $config ): StepResult { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the contract every step shares.
        return StepResult::ok();
    }

    public function client_meta(): array {
        return [
            'provides' => $this->provides(),
        ];
    }
}
