<?php
namespace YayMatik\Tests\Support;

use YayMatik\Workflow\Core\Runtime\RunRepository;
use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;

/** In-memory `runs` and `run_steps`, with the same rules as the stores (claim, finished_at, root id). */
class FakeRunRepository implements RunRepository {

    /** @var array[] Run id => row. */
    public $runs = [];

    /** @var array[] Step row id => row. */
    public $steps = [];

    /** @var int[][] Workflow id => extra recent runs [ 'all' => n, 'chained' => n ], added to the stored ones. */
    public $recent = [];

    /** @var string "Now" for claim_run(), GMT `Y-m-d H:i:s`. */
    public $now = '2026-10-12 10:00:00';

    /** @var int How many save_run() calls were made. */
    public $saves = 0;

    public function create_run( array $run ): int {
        $id                = count( $this->runs ) + 1;
        $this->runs[ $id ] = [
            'id'            => $id,
            'workflow_id'   => (int) $run['workflow_id'],
            'version_id'    => (int) $run['version_id'],
            'trigger_id'    => (string) ( $run['trigger_id'] ?? '' ),
            'subject'       => (string) ( $run['subject'] ?? '' ),
            'status'        => RunStore::STATUS_QUEUED,
            'position'      => null,
            'scope'         => $run['scope'] ?? [],
            'wait_until'    => null,
            'error'         => null,
            'parent_run_id' => (int) ( $run['parent_run_id'] ?? 0 ),
            'root_run_id'   => (int) ( $run['root_run_id'] ?? 0 ) ? (int) $run['root_run_id'] : $id,
            'depth'         => (int) ( $run['depth'] ?? 0 ),
            'started_at'    => '2026-10-12 10:00:00',
            'finished_at'   => null,
        ];
        return $id;
    }

    public function find_run( int $id ): ?array {
        return $this->runs[ $id ] ?? null;
    }

    public function claim_run( int $id, array $from ): bool {
        $run = $this->runs[ $id ] ?? null;
        if ( ! $run || ! in_array( $run['status'], $from, true ) ) {
            return false;
        }
        // Like RunStore: a waiting run only once its time has come; wait_until stays.
        if ( RunStore::STATUS_WAITING === $run['status'] && null !== $run['wait_until'] && $run['wait_until'] > $this->now ) {
            return false;
        }
        $this->runs[ $id ]['status'] = RunStore::STATUS_RUNNING;
        return true;
    }

    public function save_run( int $id, array $fields ): bool {
        if ( ! isset( $this->runs[ $id ] ) ) {
            return false;
        }
        ++$this->saves;
        foreach ( [ 'position', 'scope', 'status', 'wait_until', 'error' ] as $key ) {
            if ( array_key_exists( $key, $fields ) ) {
                // Through JSON, as the table stores it.
                $this->runs[ $id ][ $key ] = json_decode( (string) wp_json_encode( $fields[ $key ] ), true );
            }
        }
        if ( in_array( $fields['status'] ?? '', RunStore::FINISHED, true ) ) {
            $this->runs[ $id ]['finished_at'] = '2026-10-12 10:00:01';
        }
        return true;
    }

    public function recent_counts( int $workflow_id, int $seconds ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- interface signature.
        $stored = array_filter( $this->runs, fn( array $run ) => $run['workflow_id'] === $workflow_id );
        $extra  = $this->recent[ $workflow_id ] ?? [];
        return [
            'all'     => count( $stored ) + ( $extra['all'] ?? 0 ),
            'chained' => count( array_filter( $stored, fn( array $run ) => $run['depth'] > 0 ) ) + ( $extra['chained'] ?? 0 ),
        ];
    }

    public function start_step( int $run_id, string $step_id, array $input ): int {
        $id                 = count( $this->steps ) + 1;
        $this->steps[ $id ] = [
            'id'          => $id,
            'run_id'      => $run_id,
            'step_id'     => $step_id,
            'iteration'   => 0,
            'status'      => RunStepStore::STATUS_RUNNING,
            'input'       => json_decode( (string) wp_json_encode( $input ), true ),
            'output'      => [],
            'error'       => null,
            'attempt'     => 1,
            'started_at'  => '2026-10-12 10:00:00',
            'finished_at' => null,
        ];
        return $id;
    }

    public function finish_step( int $id, string $status, array $output = [], ?string $error = null ): bool {
        if ( ! isset( $this->steps[ $id ] ) ) {
            return false;
        }
        $this->steps[ $id ]['status'] = $status;
        $this->steps[ $id ]['output'] = json_decode( (string) wp_json_encode( $output ), true );
        $this->steps[ $id ]['error']  = $error;
        if ( ! in_array( $status, [ RunStepStore::STATUS_RUNNING, RunStepStore::STATUS_WAITING ], true ) ) {
            $this->steps[ $id ]['finished_at'] = '2026-10-12 10:00:01';
        }
        return true;
    }

    public function steps( int $run_id ): array {
        return array_values( array_filter( $this->steps, fn( array $row ) => $row['run_id'] === $run_id ) );
    }
}
