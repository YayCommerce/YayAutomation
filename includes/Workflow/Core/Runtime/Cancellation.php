<?php
namespace YayMatik\Workflow\Core\Runtime;

use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;

defined( 'ABSPATH' ) || exit;

/** Cancels a workflow's waiting runs when it is turned off or updated. */
final class Cancellation {

    /** Why a run stopped: its workflow was deactivated. */
    public static function turned_off(): string {
        return __( 'The workflow was deactivated.', 'yaymatik' );
    }

    /** Why a waiting run stopped: its workflow was updated. */
    public static function updated(): string {
        return __( 'The workflow was updated.', 'yaymatik' );
    }

    /**
     * Their Action Scheduler jobs stay: when one fires, the run can't be taken, so it does nothing.
     *
     * @param int    $workflow_id
     * @param string $reason
     * @return int How many runs were cancelled.
     */
    public static function waiting( int $workflow_id, string $reason ): int {
        $ids   = RunStore::waiting_ids( $workflow_id );
        $count = RunStore::cancel_waiting( $ids, $reason );
        if ( $count ) {
            RunStepStore::cancel_waiting( $ids, $reason );
        }
        return $count;
    }
}
