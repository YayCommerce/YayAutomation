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

    /** Picker section; empty for none. */
    public function category(): string {
        return '';
    }

    /** Data items every run context of this trigger holds. */
    abstract public function provides(): array;

    /**
     * Data items available from this trigger, including additions from integrations.
     *
     * Integrations should append their item ids on `yaymatik_trigger_provides`
     * instead of replacing this trigger class.
     */
    final public function resolved_provides(): array {
        $items = (array) apply_filters( 'yaymatik_trigger_provides', $this->provides(), $this );
        return array_values( array_unique( array_filter( $items, 'is_string' ) ) );
    }

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
     * The trigger scope after integrations have added their refs or snapshots.
     * A missing native subject stays missing and is never revived by a filter.
     *
     * @param array $args
     */
    final public function resolved_refs( array $args ): ?array {
        $refs = $this->refs( $args );
        if ( null === $refs ) {
            return null;
        }
        return (array) apply_filters( 'yaymatik_trigger_refs', $refs, $this, $args );
    }

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
     * Why a run about this subject must stop before its next step, or null to go on.
     *
     * @param string $subject
     */
    public function stop_reason( string $subject ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- for triggers whose subject can end a run.
        return null;
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
        $meta = [ 'provides' => $this->resolved_provides() ];
        if ( '' !== $this->category() ) {
            $meta['category'] = $this->category();
        }
        return $meta;
    }
}
