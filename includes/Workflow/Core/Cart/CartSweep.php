<?php
namespace YayMatik\Workflow\Core\Cart;

use YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Core\Storage\CartStore;
use YayMatik\Workflow\Core\Storage\Settings;

defined( 'ABSPATH' ) || exit;

/** Five-minute job marking carts left without activity as abandoned. */
final class CartSweep {

    public const HOOK = 'yaymatik_cart_sweep';

    private const EVERY = 5 * MINUTE_IN_SECONDS;

    /** Per sweep, as all its events start runs at one shutdown: a larger backlog could reach the Dispatcher's ceiling and pause the workflow. */
    private const BATCH = 200;

    /** Carts idle this long past the abandon time are skipped, so turning tracking on never emails old carts. */
    private const WINDOW = DAY_IN_SECONDS;

    public static function boot(): void {
        add_action( self::HOOK, [ self::class, 'run' ] );
    }

    public static function schedule(): void {
        as_schedule_recurring_action( time() + self::EVERY, self::EVERY, self::HOOK, [], ActionSchedulerQueue::GROUP, true );
    }

    public static function run(): void {
        if ( ! Settings::get( 'cart_tracking' ) ) {
            return;
        }
        $cutoff = time() - (int) Settings::get( 'abandon_minutes' ) * MINUTE_IN_SECONDS;
        $before = gmdate( 'Y-m-d H:i:s', $cutoff );
        $after  = gmdate( 'Y-m-d H:i:s', $cutoff - self::WINDOW );
        foreach ( CartStore::due( $before, $after, self::BATCH ) as $row ) {
            $first = null === $row['abandoned_at'];
            if ( CartStore::mark_abandoned( (int) $row['id'], $row['last_activity_at'], $first ? self::kind( $row ) : '' ) && $first ) {
                do_action( 'yaymatik_cart_abandoned', (int) $row['id'] );
            }
        }
    }

    /**
     * @param array $row
     */
    public static function kind( array $row ): string {
        return null !== $row['checkout_at'] ? 'checkout_abandoned' : 'cart_abandoned';
    }
}
