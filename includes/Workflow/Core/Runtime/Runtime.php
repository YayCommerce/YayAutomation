<?php
namespace YayMatik\Workflow\Core\Runtime;

defined( 'ABSPATH' ) || exit;

/** Builds the Dispatcher and the Worker over the real stores and Action Scheduler, and hooks them. */
final class Runtime {

    /** @var Dispatcher|null */
    private static $dispatcher = null;

    /** @var Worker|null */
    private static $worker = null;

    public static function boot(): void {
        if ( self::$worker ) {
            return;
        }
        $runs      = new DbRunRepository();
        $queue     = new ActionSchedulerQueue();
        $workflows = new RepositoryWorkflows();

        $worker           = new Worker( $runs, new VersionGraphSource(), $queue, $workflows );
        self::$worker     = $worker;
        self::$dispatcher = new Dispatcher(
            $runs,
            $queue,
            $workflows,
            function ( int $run_id ) use ( $worker ): void {
                $worker->run( $run_id );
            },
            [ $worker, 'current' ]
        );
        $worker->register();
        // Not on plugins_loaded: the trigger list reads the modules, and a plugin loaded after this one adds its own then.
        add_action( 'init', [ self::$dispatcher, 'register' ], 0 );
    }

    /** Null before `boot()`, e.g. without WooCommerce. */
    public static function dispatcher(): ?Dispatcher {
        return self::$dispatcher;
    }

    /** Null before `boot()`. */
    public static function worker(): ?Worker {
        return self::$worker;
    }
}
