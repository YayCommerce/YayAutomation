<?php
namespace YayAutomation\Workflow\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Runs and step rows as the REST API returns them, matching the TS `Run` / `RunStep` / `RunDetail`
 * types: camelCase keys, ids as strings (like workflows and versions), dates as ISO 8601 in UTC.
 * Never the scope or the cursor (`position`): those are the executor's.
 */
final class RunPresenter {

    /**
     * @param array $run Row from `RunStore::find()` / `list()`.
     */
    public static function run( array $run ): array {
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
     * A run with its steps in the order they ran.
     *
     * @param array   $run   Row from `RunStore::find()`.
     * @param array[] $steps Rows from `RunStepStore::for_run()`.
     */
    public static function detail( array $run, array $steps ): array {
        return self::run( $run ) + [ 'steps' => array_map( [ self::class, 'step' ], $steps ) ];
    }

    /**
     * @param array $step Row from `RunStepStore::for_run()`.
     */
    public static function step( array $step ): array {
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
     * @param string|null $gmt Stored date.
     */
    public static function iso( ?string $gmt ): ?string {
        if ( null === $gmt || '' === $gmt || 0 === strpos( $gmt, '0000-00-00' ) ) {
            return null;
        }
        return ( new \DateTimeImmutable( $gmt, new \DateTimeZone( 'UTC' ) ) )->format( 'c' );
    }
}
