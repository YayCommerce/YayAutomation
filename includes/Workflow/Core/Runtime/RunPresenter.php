<?php
namespace YayMatik\Workflow\Core\Runtime;

defined( 'ABSPATH' ) || exit;

/** Runs, step rows and run stats shaped like the TS types, without scope or cursor. */
final class RunPresenter {

    /**
     * @param array $run
     */
    private static function run( array $run ): array {
        return [
            'id'          => (string) $run['id'],
            'workflowId'  => (string) $run['workflow_id'],
            'versionId'   => (string) $run['version_id'],
            'triggerId'   => (string) $run['trigger_id'],
            'subject'     => (string) $run['subject'],
            'status'      => (string) $run['status'],
            'error'       => null === $run['error'] ? null : (string) $run['error'],
            'waitUntil'   => self::iso( $run['wait_until'] ?? null ),
            // 0 in the table: started by an event, not by another run.
            'parentRunId' => (int) $run['parent_run_id'] ? (string) $run['parent_run_id'] : null,
            'rootRunId'   => (string) $run['root_run_id'],
            'depth'       => (int) $run['depth'],
            'startedAt'   => self::iso( $run['started_at'] ?? null ),
            'finishedAt'  => self::iso( $run['finished_at'] ?? null ),
        ];
    }

    /**
     * A row of a workflow's runs list.
     *
     * @param array $run
     * @param int   $steps_run
     */
    public static function item( array $run, int $steps_run ): array {
        $subject = self::subject( (string) $run['subject'] );
        return self::run( $run ) + [
            'subjectLabel' => $subject['label'],
            'subjectUrl'   => $subject['url'],
            'stepsRun'     => $steps_run,
        ];
    }

    /**
     * What a run is about, for people: "Order #1012" and its admin URL (null once the order is gone).
     *
     * @param string $subject E.g. `order:12`.
     * @return array `label`, `url`.
     */
    public static function subject( string $subject ): array {
        list( $type, $id ) = array_pad( explode( ':', $subject, 2 ), 2, '' );
        if ( 'order' !== $type || (int) $id <= 0 ) {
            return [
                'label' => $subject,
                'url'   => null,
            ];
        }
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $id ) : false;
        return [
            /* translators: %s: order number */
            'label' => sprintf( __( 'Order #%s', 'yaymatik' ), $order ? $order->get_order_number() : (int) $id ),
            'url'   => $order ? $order->get_edit_order_url() : null,
        ];
    }

    /**
     * A run with its step rows; each names its step type from the pinned graph (null when the node is gone).
     *
     * @param array      $run
     * @param array[]    $steps
     * @param array|null $graph
     */
    public static function detail( array $run, array $steps, ?array $graph ): array {
        $types = [];
        foreach ( (array) ( $graph['nodes'] ?? [] ) as $node ) {
            $block = $node['data']['block'] ?? null;
            if ( is_string( $block ) && '' !== $block ) {
                $types[ (string) ( $node['id'] ?? '' ) ] = $block;
            }
        }
        return self::run( $run ) + [
            'steps' => array_map(
                fn( array $step ) => self::step( $step ) + [ 'stepType' => $types[ (string) $step['step_id'] ] ?? null ],
                $steps
            ),
        ];
    }

    /**
     * A workflow's run stats, zero when it never ran.
     *
     * @param array|null $stats From `RunStore::stats()`.
     */
    public static function stats( ?array $stats ): array {
        return [
            'runs30d'     => (int) ( $stats['recent'] ?? 0 ),
            'lastRunAt'   => self::iso( $stats['last_run'] ?? null ),
            'waitingRuns' => (int) ( $stats['waiting'] ?? 0 ),
        ];
    }

    private static function step( array $step ): array {
        return [
            'id'         => (string) $step['id'],
            'runId'      => (string) $step['run_id'],
            'stepId'     => (string) $step['step_id'],
            'iteration'  => (int) $step['iteration'],
            'status'     => (string) $step['status'],
            'input'      => (object) $step['input'],
            'output'     => (object) $step['output'],
            'error'      => null === $step['error'] ? null : (string) $step['error'],
            'attempt'    => (int) $step['attempt'],
            'startedAt'  => self::iso( $step['started_at'] ?? null ),
            'finishedAt' => self::iso( $step['finished_at'] ?? null ),
        ];
    }

    /**
     * Stored GMT `Y-m-d H:i:s` → `2026-10-12T08:03:04+00:00`; empty → null.
     *
     * @param string|null $gmt
     */
    public static function iso( ?string $gmt ): ?string {
        if ( null === $gmt || '' === $gmt || 0 === strpos( $gmt, '0000-00-00' ) ) {
            return null;
        }
        return ( new \DateTimeImmutable( $gmt, new \DateTimeZone( 'UTC' ) ) )->format( 'c' );
    }
}
