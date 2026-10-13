<?php
namespace YayAutomation\Workflow\Steps\Core;

use YayAutomation\Workflow\Data\Scope;

defined( 'ABSPATH' ) || exit;

/**
 * The step that starts a workflow. A trigger hooks its own events (`listen()`) and reports each one
 * with JSON-safe args; the Dispatcher matches workflows with `context()` and stores `refs()` as the
 * run's trigger data.
 */
abstract class Trigger extends Step {

    public function kind(): string {
        return self::KIND_TRIGGER;
    }

    /** Data items every run context of this trigger holds. */
    abstract public function provides(): array;

    /**
     * Hooks the events. Calls `$emit( $args )` once per event.
     *
     * @param callable $emit fn( array $args ): void. Args must survive JSON (they go through Action Scheduler).
     */
    abstract public function listen( callable $emit ): void;

    /**
     * Run context for an event, or null when its subject is gone (e.g. the order was deleted).
     *
     * @param array $args Event args from `listen()`.
     */
    abstract public function context( array $args ): ?array;

    /**
     * What a run stores for the event, its `trigger` scope section: Refs to the entities plus facts
     * only true at trigger time (e.g. status from / to). Never whole objects. Null when the subject is gone.
     *
     * @param array $args Event args from `listen()`.
     * @return array|null Key => Ref or plain value.
     */
    abstract public function refs( array $args ): ?array;

    /**
     * What the event is about, e.g. `order:12`. Stored on the run, for history and logs.
     *
     * @param array $args Event args from `listen()`.
     */
    abstract public function subject( array $args ): string;

    /**
     * Whether a workflow with this config runs for the event.
     *
     * @param array $context Run context from `context()`.
     * @param array $config  Sanitized, valid config.
     */
    public function matches( array $context, array $config ): bool {
        return true;
    }

    /**
     * Last check before the event's workflows are queued, once per event. Lets a trigger fire only
     * once per subject across requests.
     *
     * @param array $args Event args from `listen()`.
     */
    public function claim( array $args ): bool {
        return true;
    }

    /**
     * Nothing to do: what the trigger supplies is already in the Scope.
     *
     * @param Scope $scope  Run scope.
     * @param array $config Sanitized, valid config.
     */
    public function run( Scope $scope, array $config ): StepResult { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the contract every step shares.
        return StepResult::ok();
    }

    public function client_meta(): array {
        return [
            'plugin'   => $this->plugin(),
            'provides' => $this->provides(),
        ];
    }
}
