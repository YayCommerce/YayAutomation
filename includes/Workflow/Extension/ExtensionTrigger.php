<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Workflow\Core\Steps\Trigger;

defined( 'ABSPATH' ) || exit;

/**
 * Another plugin's trigger, from its declaration (docs/extension-api.md §5.3). Its events come
 * through `yaymatik_fire` (`Fire`), already mapped by `args()`.
 *
 * @internal
 */
final class ExtensionTrigger extends Trigger {

    use ExtensionStep;

    /**
     * @param string $module
     * @param string $id
     * @param array  $args From `Declaration::trigger()`.
     */
    public function __construct( string $module, string $id, array $args ) {
        $this->module = $module;
        $this->id     = $id;
        $this->args   = $args;
    }

    public function provides(): array {
        return $this->args['provides'];
    }

    /**
     * The event's args from a payload; null (no run) when it lacks what `provides` needs. Keys it
     * can't use are dropped with a diagnostic.
     *
     * @param array $payload
     */
    public function args( array $payload ): ?array {
        $args = [];
        foreach ( $payload as $key => $value ) {
            if ( ! isset( RefMap::IDS[ $key ] ) ) {
                $this->diagnose( 'payload', sprintf( 'Payload key "%s" is not an id or a declared fact; it was dropped.', $key ) );
                continue;
            }
            $id = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ? (int) $value : 0;
            if ( $id <= 0 ) {
                $this->diagnose( 'payload', sprintf( 'Payload key "%s" is not a positive id; it was dropped.', $key ) );
                continue;
            }
            $args[ $key ] = $id;
        }
        foreach ( $this->provides() as $item ) {
            if ( ! RefMap::gives( $item, $args ) ) {
                $this->diagnose( 'fire', sprintf( 'The payload gives no "%s", which this trigger provides; no run was started.', $item ) );
                return null;
            }
        }
        return $args;
    }

    /**
     * Nothing to hook: the plugin fires `yaymatik_fire`.
     *
     * @param callable $emit
     */
    public function listen( callable $emit ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- events come through Fire.
    }

    /**
     * @param array $args
     */
    public function context( array $args ): ?array {
        return [ 'args' => $args ];
    }

    /**
     * @param array $args
     */
    public function refs( array $args ): ?array {
        return RefMap::refs( $args );
    }

    /**
     * @param array $args
     */
    public function subject( array $args ): string {
        return RefMap::subject( $args );
    }

    /**
     * @param array $context
     * @param array $config
     */
    public function matches( array $context, array $config ): bool {
        if ( ! isset( $this->args['matches'] ) ) {
            return true;
        }
        return Guard::call(
            $this->module,
            'matches',
            fn() => (bool) ( $this->args['matches'] )( $context['args'], $config ),
            fn() => false
        );
    }

    public function claims_per_workflow(): bool {
        return 'subject' === ( $this->args['once_per'] ?? null );
    }

    /**
     * Dropped keys and refused runs are separate callbacks, so the throttle never hides one behind the other.
     *
     * @param string $callback
     * @param string $message
     */
    private function diagnose( string $callback, string $message ): void {
        Diagnostics::record( $this->module, "{$callback} {$this->id}", $message );
    }
}
