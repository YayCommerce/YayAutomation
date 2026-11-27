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

    private const FACT_BYTES = 1024;

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

    public function facts(): array {
        return $this->args['facts'];
    }

    /**
     * The event's args from a payload; null (no run) when it lacks what `provides` needs. Keys it
     * can't use are dropped, and a fact over 1 KB is cut, with a diagnostic.
     *
     * @param array $payload
     */
    public function args( array $payload ): ?array {
        $args = [];
        $ids  = RefMap::keys( ( $this->args['items'] )() );
        foreach ( $payload as $key => $value ) {
            if ( isset( $this->facts()[ $key ] ) ) {
                $fact = $this->fact( (string) $key, $value );
                if ( null !== $fact ) {
                    $args[ $key ] = $fact;
                }
                continue;
            }
            if ( ! isset( $ids[ $key ] ) ) {
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
            if ( ! RefMap::gives( $item, $args, ( $this->args['items'] )() ) ) {
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
        return RefMap::refs( $args, ( $this->args['items'] )() ) + array_intersect_key( $args, $this->facts() );
    }

    /**
     * @param array $args
     */
    public function subject( array $args ): string {
        return RefMap::subject( $args, ( $this->args['items'] )() );
    }

    public function sample_facts(): array {
        return array_intersect_key( (array) ( $this->args['sample_facts'] ?? [] ), $this->facts() );
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
     * A fact's value as stored: a scalar, text cut at 1 KB; null (dropped) for anything else.
     *
     * @param string $key
     * @param mixed  $value
     * @return string|int|float|bool|null
     */
    private function fact( string $key, $value ) {
        if ( ! is_scalar( $value ) ) {
            $this->diagnose( 'payload', sprintf( 'Fact "%s" is not a single value; it was dropped.', $key ) );
            return null;
        }
        if ( is_string( $value ) && strlen( $value ) > self::FACT_BYTES ) {
            $this->diagnose( 'payload', sprintf( 'Fact "%s" is over 1 KB; it was cut.', $key ) );
            return mb_strcut( $value, 0, self::FACT_BYTES, 'UTF-8' );
        }
        return $value;
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
