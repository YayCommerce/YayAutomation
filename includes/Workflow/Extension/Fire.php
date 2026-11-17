<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Utils\Logger;
use YayMatik\Workflow\Core\Runtime\Dispatcher;
use YayMatik\Workflow\Core\Runtime\Workflows;
use YayMatik\Workflow\Core\Steps\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Handles `yaymatik_fire`: an extension trigger's event goes the core triggers' way, through
 * `Dispatcher::collect()` (claims, ceiling, loop guard). Never throws into the plugin's request.
 *
 * @internal
 */
final class Fire {

    public const HOOK = 'yaymatik_fire';

    /** @var Workflows */
    private $workflows;

    /** @var Dispatcher */
    private $dispatcher;

    /** @var callable fn( string $id ): ?Step */
    private $step;

    /**
     * @param Workflows  $workflows
     * @param Dispatcher $dispatcher
     * @param callable   $step Finds a registered step by id.
     */
    public function __construct( Workflows $workflows, Dispatcher $dispatcher, callable $step ) {
        $this->workflows  = $workflows;
        $this->dispatcher = $dispatcher;
        $this->step       = $step;
    }

    public function register(): void {
        add_action( self::HOOK, [ $this, 'handle' ], 10, 2 );
    }

    /**
     * @param mixed $trigger_id
     * @param mixed $payload
     */
    public function handle( $trigger_id, $payload = [] ): void {
        try {
            if ( ! is_string( $trigger_id ) || ! is_array( $payload ) ) {
                return;
            }
            // Cheapest check first: most events have no workflow, and the registry isn't built for them.
            $active = $this->workflows->active_trigger_ids();
            if ( null !== $active && ! in_array( $trigger_id, $active, true ) ) {
                return;
            }
            // Building the registry now would leave out plugins that register later in the request.
            if ( ! did_action( 'init' ) ) {
                add_action( 'init', fn() => $this->handle( $trigger_id, $payload ), 1 );
                return;
            }
            $trigger = ( $this->step )( $trigger_id );
            if ( ! $trigger instanceof ExtensionTrigger ) {
                return;
            }
            $args = $trigger->args( $payload );
            if ( null !== $args ) {
                $this->dispatcher->collect( $trigger, $args );
            }
        } catch ( \Throwable $e ) {
            Logger::error( 'Could not handle ' . self::HOOK . ': ' . $e->getMessage(), [ 'trigger' => $trigger_id ] );
        }
    }
}
