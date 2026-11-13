<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Steps\Action;
use YayMatik\Workflow\Core\Steps\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Another plugin's action, from its declaration (docs/extension-api.md §5.4).
 *
 * @internal
 */
class ExtensionAction extends Action {

    use ExtensionStep;

    /**
     * @param string $module
     * @param string $id
     * @param array  $args From `Declaration::action()`.
     */
    public function __construct( string $module, string $id, array $args ) {
        $this->module = $module;
        $this->id     = $id;
        $this->args   = $args;
    }

    public function requires(): array {
        return $this->args['requires'] ?? [];
    }

    public function emits(): array {
        return $this->args['emits'] ?? [];
    }

    public function outputs(): array {
        return $this->args['outputs'];
    }

    public function on_error(): string {
        return $this->args['on_error'] ?? self::ON_ERROR_STOP;
    }

    /**
     * @param array $config
     */
    public function sample_outputs( array $config ): array {
        if ( ! isset( $this->args['sample_outputs'] ) ) {
            return [];
        }
        return Guard::call(
            $this->module,
            'sample_outputs',
            fn() => array_intersect_key( (array) ( $this->args['sample_outputs'] )( $config ), $this->outputs() ),
            fn() => []
        );
    }

    /**
     * A throw fails the step with its message; the Executor keeps only declared outputs.
     *
     * @param Scope $scope
     * @param array $config
     */
    public function run( Scope $scope, array $config ): StepResult {
        return Guard::call(
            $this->module,
            'run',
            function () use ( $scope, $config ) {
                $result = ( $this->args['run'] )( $config, new RunAdapter( $scope ) );
                if ( $result instanceof StepResult ) {
                    return $result;
                }
                Diagnostics::record( $this->module, 'run', 'run returned something other than $run->done(), $run->skip() or $run->fail().' );
                return StepResult::failed( __( 'The step returned no result.', 'yaymatik' ) );
            },
            fn( \Throwable $e ) => StepResult::failed( $e->getMessage() )
        );
    }

    /** No category: no picker heading. `result`: the run-detail line, '' = the summary. */
    public function client_meta(): array {
        return array_filter( parent::client_meta(), fn( $value, string $key ) => 'category' !== $key || '' !== $value, ARRAY_FILTER_USE_BOTH )
            + [ 'result' => $this->result() ];
    }

    /** Run-detail line with `%output%` / `%field%` placeholders; '' = the summary. */
    protected function result(): string {
        return $this->args['result'] ?? '';
    }
}
