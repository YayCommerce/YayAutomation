<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Workflow\Core\Steps\Trigger;

defined( 'ABSPATH' ) || exit;

/**
 * What extension triggers and actions share: the declared keys of §5.2.
 *
 * @internal
 */
trait ExtensionStep {

    /** @var string */
    private $module;

    /** @var string */
    private $id;

    /** @var array From `Declaration`. */
    private $args;

    public function id(): string {
        return $this->id;
    }

    public function label(): string {
        return $this->args['label'];
    }

    public function description(): string {
        return $this->args['description'];
    }

    /** '' = the editor draws the kind's icon. */
    public function icon(): string {
        return $this->args['icon'] ?? '';
    }

    public function fields(): array {
        return $this->args['fields'];
    }

    public function summary(): string {
        return $this->args['summary'] ?? '';
    }

    public function category(): string {
        return $this->args['category'] ?? '';
    }

    public function setup_issue(): ?string {
        if ( ! isset( $this->args['setup_issue'] ) ) {
            return null;
        }
        return Guard::call(
            $this->module,
            'setup_issue',
            function () {
                $issue = ( $this->args['setup_issue'] )();
                return is_string( $issue ) && '' !== $issue ? $issue : null;
            },
            fn() => null
        );
    }

    /**
     * The declared `validate( $config, $available )`: its first `[ field => message ]`.
     *
     * @param array        $config
     * @param array        $available
     * @param Trigger|null $trigger
     */
    public function context_error( array $config, array $available, ?Trigger $trigger ): ?\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the declared callable gets the public `( $config, $available )`.
        if ( ! isset( $this->args['validate'] ) ) {
            return null;
        }
        return Guard::call(
            $this->module,
            'validate',
            function () use ( $config, $available ) {
                $errors = ( $this->args['validate'] )( $config, $available );
                if ( ! is_array( $errors ) || ! $errors ) {
                    return null;
                }
                $field = array_key_first( $errors );
                return $this->invalid( (string) $errors[ $field ], is_string( $field ) ? $field : '' );
            },
            fn() => $this->invalid( __( 'This step’s settings couldn’t be checked.', 'yaymatik' ) )
        );
    }
}
