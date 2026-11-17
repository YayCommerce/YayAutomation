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

    /** No category: no picker heading. `result`: the run-detail line, '' = the summary. `editor`: its field and button. */
    public function client_meta(): array {
        $meta = array_filter( parent::client_meta(), fn( $value, string $key ) => 'category' !== $key || '' !== $value, ARRAY_FILTER_USE_BOTH )
            + [ 'result' => $this->result() ];
        if ( isset( $this->args['editor'] ) ) {
            $meta['editor'] = [
                'field'  => $this->args['editor']['field'],
                'button' => $this->args['editor']['button'],
            ];
        }
        return $meta;
    }

    /**
     * The iframe editor's URL for this context, or why not: another origin than the admin's is refused.
     *
     * @param array $context
     * @return string|\WP_Error
     */
    public function editor_url( array $context ) {
        $url = Guard::call( $this->module, 'editor url', fn() => ( $this->args['editor']['url'] )( $context ), fn( \Throwable $e ) => $e );
        if ( $url instanceof \Throwable ) {
            return self::editor_refused();
        }
        if ( ! is_string( $url ) || ! self::same_origin( $url, admin_url() ) ) {
            Diagnostics::record( $this->module, 'editor url', 'The editor URL must be a string on this site\'s admin origin (admin_url()).' );
            return self::editor_refused();
        }
        return $url;
    }

    private static function editor_refused(): \WP_Error {
        return new \WP_Error( 'yaymatik_editor_url', __( 'This step’s editor couldn’t be opened. Details are in Settings › Health check.', 'yaymatik' ), [ 'status' => 400 ] );
    }

    /**
     * Same scheme, host and port.
     *
     * @param string $url
     * @param string $admin
     */
    private static function same_origin( string $url, string $admin ): bool {
        $origin = function ( string $address ): ?string {
            $parts = wp_parse_url( $address );
            if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
                return null;
            }
            $scheme = strtolower( $parts['scheme'] );
            $port   = $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 );
            return $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port;
        };
        return null !== $origin( $url ) && $origin( $url ) === $origin( $admin );
    }

    /** Run-detail line with `{{ output.<key> }}` / `{{ config.<field> }}` placeholders; '' = the summary. */
    protected function result(): string {
        return $this->args['result'] ?? '';
    }
}
