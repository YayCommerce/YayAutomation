<?php
namespace YayMatik\Workflow\Core\Runtime;

use YayMatik\Workflow\Core\Definition\GraphSchema;
use YayMatik\Workflow\Core\Storage\WorkflowStore;

defined( 'ABSPATH' ) || exit;

/** Daily job finding each person's last real order that is a set number of days old, in batches. */
final class InactiveSweep {

    public const HOOK       = 'yaymatik_inactive_sweep';
    public const BATCH_HOOK = 'yaymatik_inactive_sweep_batch';

    private const TRIGGER = 'customer_inactive';

    /** Per batch: more events at once could reach the Dispatcher's ceiling and pause the workflow. */
    private const BATCH = 200;

    private const NEXT_AFTER = 2 * MINUTE_IN_SECONDS;

    /** Days an order stays findable, so a late job or a failed start gets another try. */
    private const WINDOW_DAYS = 7;

    /** Literal, as PHPUnit runs without WordPress's time constants. */
    private const DAY = 86400;

    // Orders that don't count as ordering. `wc-checkout-draft` is added through a filter, so it's taken out by name; another plugin's status counts.
    private const NOT_REAL = [ 'wc-pending', 'wc-failed', 'wc-cancelled', 'wc-checkout-draft' ];

    public static function boot(): void {
        add_action( self::HOOK, [ self::class, 'run' ] );
        add_action( self::BATCH_HOOK, [ self::class, 'batch' ], 10, 2 );
    }

    public static function schedule(): void {
        as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, [], ActionSchedulerQueue::GROUP, true );
    }

    /** Replaces a missing or late job with a new one. */
    public static function reschedule(): void {
        as_unschedule_all_actions( self::HOOK, [], ActionSchedulerQueue::GROUP );
        self::schedule();
    }

    /** One job per days value, so a slow or failing value can't starve the others. */
    public static function run(): void {
        foreach ( self::active_days() as $days ) {
            as_schedule_single_action( time(), self::BATCH_HOOK, [ $days, 1 ], ActionSchedulerQueue::GROUP, true );
        }
    }

    /**
     * Fires the event for one page of candidates; a full page queues the next.
     *
     * @param int $days
     * @param int $page
     */
    public static function batch( int $days, int $page ): void {
        // Turning off the last workflow on these days ends the chain.
        if ( ! in_array( $days, self::active_days(), true ) ) {
            return;
        }
        $ids = self::candidates( $days, $page );
        // Queued first, so a failure on one order doesn't hold back the pages after it.
        if ( self::BATCH === count( $ids ) ) {
            as_schedule_single_action( time() + self::NEXT_AFTER, self::BATCH_HOOK, [ $days, $page + 1 ], ActionSchedulerQueue::GROUP );
        }
        foreach ( $ids as $id ) {
            $order = wc_get_order( $id );
            if ( $order instanceof \WC_Order && self::is_last_order( $order ) ) {
                do_action( 'yaymatik_customer_inactive', $id, $days );
            }
        }
        // A long `wp action-scheduler run` has one shutdown for many batches; they'd start their runs together.
        Runtime::dispatcher()->flush();
    }

    /**
     * @param array[] $workflows
     * @return int[] Distinct.
     */
    public static function days_of( array $workflows ): array {
        $days = [];
        foreach ( $workflows as $workflow ) {
            $node   = GraphSchema::trigger( $workflow['graph']['nodes'] ?? [] );
            $days[] = (int) ( $node['data']['config']['days'] ?? 0 );
        }
        return array_values( array_unique( array_filter( $days ) ) );
    }

    /**
     * `date_created` range for orders `$days` to `$days` + 7 days old.
     *
     * @param int $days
     * @param int $now
     */
    public static function window( int $days, int $now ): string {
        // Timestamps: a date string would be day precision in the site's timezone.
        return ( $now - ( $days + self::WINDOW_DAYS ) * self::DAY ) . '...' . ( $now - $days * self::DAY );
    }

    private static function active_days(): array {
        return self::days_of( WorkflowStore::active_for_trigger( self::TRIGGER ) );
    }

    /**
     * @param int $days
     * @param int $page
     * @return int[]
     */
    private static function candidates( int $days, int $page ): array {
        return wc_get_orders(
            [
                'type'         => 'shop_order',
                'status'       => self::real_statuses(),
                'date_created' => self::window( $days, time() ),
                'orderby'      => 'date ID',
                'order'        => 'ASC',
                'limit'        => self::BATCH,
                'page'         => $page,
                'return'       => 'ids',
            ]
        );
    }

    /**
     * Whether the order is the person's latest, by billing email and by account.
     *
     * @param \WC_Order $order
     */
    private static function is_last_order( \WC_Order $order ): bool {
        // An anonymized order's billing email is a placeholder, not a person.
        $email = $order->get_billing_email();
        if ( '' === $email || $order->get_meta( '_anonymized' ) ) {
            return false;
        }
        $id = $order->get_id();
        if ( self::latest_order_id( [ 'billing_email' => $email ] ) !== $id ) {
            return false;
        }
        // `customer_id => 0` would match every guest.
        $customer_id = $order->get_customer_id();
        return $customer_id <= 0 || self::latest_order_id( [ 'customer_id' => $customer_id ] ) === $id;
    }

    /**
     * Same-second orders: the higher id is the last.
     *
     * @param array $person
     */
    private static function latest_order_id( array $person ): int {
        $ids = wc_get_orders(
            $person + [
                'type'    => 'shop_order',
                'status'  => self::real_statuses(),
                'limit'   => 1,
                'orderby' => 'date ID',
                'order'   => 'DESC',
                'return'  => 'ids',
            ]
        );
        return (int) ( $ids[0] ?? 0 );
    }

    /** @return string[] `wc-` prefixed. */
    private static function real_statuses(): array {
        return array_values( array_diff( array_keys( wc_get_order_statuses() ), self::NOT_REAL ) );
    }
}
