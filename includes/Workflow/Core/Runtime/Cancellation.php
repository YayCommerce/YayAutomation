<?php
namespace YayMatik\Workflow\Core\Runtime;

use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;

defined( 'ABSPATH' ) || exit;

/** Cancels waiting runs: a workflow's when it is turned off or updated, a cart's when it is ordered or gone or its shopper unsubscribes, a person's when a Stop waiting runs step says so. */
final class Cancellation {

    /** Why a run stopped: its workflow was deactivated. */
    public static function turned_off(): string {
        return __( 'The workflow was deactivated.', 'yaymatik' );
    }

    /** Why a waiting run stopped: its workflow was updated. */
    public static function updated(): string {
        return __( 'The workflow was updated.', 'yaymatik' );
    }

    /** Why a run about a cart stopped: the cart's row is gone. */
    public static function cart_gone(): string {
        return __( 'The cart was emptied or deleted.', 'yaymatik' );
    }

    /** Why a run about a cart stopped: its shopper unsubscribed. */
    public static function unsubscribed(): string {
        return __( 'The shopper unsubscribed from these emails.', 'yaymatik' );
    }

    /**
     * Why a run about a cart stopped: its shopper ordered.
     *
     * @param int $order_id
     */
    public static function order_placed( int $order_id ): string {
        $order = wc_get_order( $order_id );
        /* translators: %s: order number */
        return sprintf( __( 'The shopper placed order #%s.', 'yaymatik' ), $order ? $order->get_order_number() : $order_id );
    }

    /**
     * Their Action Scheduler jobs stay: when one fires, the run can't be taken, so it does nothing.
     *
     * @param int    $workflow_id
     * @param string $reason
     * @return int How many runs were cancelled.
     */
    public static function waiting( int $workflow_id, string $reason ): int {
        return self::cancel( RunStore::waiting_ids( $workflow_id ), $reason );
    }

    /**
     * @param int[]  $cart_ids
     * @param string $reason
     * @return int How many runs were cancelled.
     */
    public static function carts( array $cart_ids, string $reason ): int {
        return self::cancel( RunStore::waiting_ids_for_subjects( array_map( fn( int $id ) => 'cart:' . $id, $cart_ids ) ), $reason );
    }

    /**
     * Waiting runs about these subjects, of these workflows only.
     *
     * @param string[] $subjects
     * @param int[]    $workflow_ids
     * @param string   $reason
     * @return int How many runs were cancelled.
     */
    public static function for_subjects( array $subjects, array $workflow_ids, string $reason ): int {
        return $workflow_ids ? self::cancel( RunStore::waiting_ids_for_subjects( $subjects, $workflow_ids ), $reason ) : 0;
    }

    /**
     * @param int[]  $ids
     * @param string $reason
     */
    private static function cancel( array $ids, string $reason ): int {
        $count = RunStore::cancel_waiting( $ids, $reason );
        if ( $count ) {
            RunStepStore::cancel_waiting( $ids, $reason );
        }
        return $count;
    }
}
