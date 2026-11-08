<?php
namespace YayMatik\Workflow\Core\Steps;

use YayMatik\Workflow\Core\Data\Scope;

defined( 'ABSPATH' ) || exit;

/**
 * One workflow step type; PHP `fields()` is the one source of its settings.
 */
abstract class Step {

    public const KIND_TRIGGER   = 'trigger';
    public const KIND_CONDITION = 'condition';
    public const KIND_ACTION    = 'action';

    /** A failed step ends the run. */
    public const ON_ERROR_STOP = 'stop';

    /** A failed step is recorded and the run goes on with the next step. */
    public const ON_ERROR_CONTINUE = 'continue';

    abstract public function id(): string;

    abstract public function kind(): string;

    /** Name in the editor, e.g. "Add order note". */
    abstract public function label(): string;

    /** One sentence on what the step does, for the picker. */
    abstract public function description(): string;

    /** A name from `StepIcons::NAMES`. */
    abstract public function icon(): string;

    /** @return Field[] In form order. */
    abstract public function fields(): array;

    /** Card text with `%field key%` placeholders, e.g. "Change the order status to %status%". */
    abstract public function summary(): string;

    /**
     * What later steps can read at `steps.<id>.<key>`.
     *
     * @return array Key => [ 'label' => string, 'type' => Data\Type, 'options'? => value => label ].
     */
    public function outputs(): array {
        return [];
    }

    /**
     * Does the step's work and says how it went.
     *
     * @param Scope $scope
     * @param array $config Sanitized, valid config.
     */
    abstract public function run( Scope $scope, array $config ): StepResult;

    /** What happens to the run when this step fails: ON_ERROR_STOP or ON_ERROR_CONTINUE. */
    public function on_error(): string {
        return self::ON_ERROR_STOP;
    }

    /**
     * Raw input as the stored config shape. Never fails; `validate()` decides if it's usable.
     *
     * @param mixed $config
     */
    public function sanitize( $config ): array {
        return Field::sanitize_all( $this->fields(), $config );
    }

    /**
     * Whether a sanitized config can run; a step adds the checks fields can't express.
     *
     * @param array $config
     * @return true|\WP_Error Error data holds the `field` it is about, when there is one.
     */
    public function validate( array $config ) {
        return $this->field_error( $config ) ?? true;
    }

    /**
     * Data items this step reads with the config; the trigger must supply them.
     *
     * @param array $config
     */
    public function uses( array $config ): array {
        return [];
    }

    /**
     * Made-up outputs for previews of later steps; empty = they read empty.
     *
     * @param array $config
     */
    public function sample_outputs( array $config ): array {
        return [];
    }

    /** Extra facts merged into this step's catalog entry. */
    public function client_meta(): array {
        return [];
    }

    /**
     * The fields' first broken rule as an error, or null.
     *
     * @param array $config
     */
    protected function field_error( array $config ): ?\WP_Error {
        $error = Field::first_error( $this->fields(), $config, $this->label() );
        return $error ? $this->invalid( $error['message'], $error['field'] ) : null;
    }

    /**
     * @param string $message
     * @param string $field
     * @param array  $data More about where, e.g. `rule` => a rule's path in a condition.
     */
    protected function invalid( string $message, string $field = '', array $data = [] ): \WP_Error {
        return new \WP_Error( 'yaymatik_invalid_config', $message, '' === $field ? '' : [ 'field' => $field ] + $data );
    }

    /**
     * Raw input as trimmed text; '' for arrays and objects.
     *
     * @param mixed $value
     */
    protected static function scalar( $value ): string {
        return is_scalar( $value ) ? trim( (string) $value ) : '';
    }
}
