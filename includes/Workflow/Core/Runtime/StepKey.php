<?php
namespace YayMatik\Workflow\Core\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * A run-step row's key, `<row id>.<run start, Unix>`: the email mark and `Step::key()`.
 * Row ids start over when the tables are recreated or a backup is restored, but a product's ledger stays; a run started
 * after that is always later than any run the ledger saw, so a key never comes back.
 */
final class StepKey {

    /**
     * @param int    $row
     * @param string $run_started_at GMT, as stored.
     */
    public static function of( int $row, string $run_started_at ): string {
        return $row . '.' . (int) strtotime( $run_started_at . ' UTC' );
    }

    /**
     * The row id in a key; 0 when it isn't one.
     *
     * @param mixed $key
     */
    public static function row( $key ): int {
        return is_string( $key ) && preg_match( '/^([1-9]\d*)\.\d+$/', $key, $match ) ? (int) $match[1] : 0;
    }
}
