<?php
namespace YayAutomation\Workflow\Steps\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The step that starts a workflow. A trigger hooks its own events (`listen()`) and reports each one
 * with JSON-safe args; the Runner queues the matching workflows and later rebuilds the run context
 * from those args (`context()`).
 */
abstract class Trigger extends Step {

    public function kind(): string {
        return self::KIND_TRIGGER;
    }

    /** Plugin the trigger is grouped under in the picker (see Workflow/Extend/Plugins). */
    abstract public function plugin(): string;

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
     * What the event is about, e.g. `order:12`. A workflow never re-triggers itself on the same subject.
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

    public function client_meta(): array {
        return [
            'plugin'   => $this->plugin(),
            'provides' => $this->provides(),
        ];
    }
}
