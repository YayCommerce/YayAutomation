<?php
namespace YayMatik\Workflow\Steps\Core;

use YayMatik\Workflow\Data\Scope;

defined( 'ABSPATH' ) || exit;

/**
 * One workflow step type. PHP is the one source of a step's settings (`fields()`); the admin app
 * gets them through the catalog and draws the form and checks from them. Checks a field can't
 * express go in `validate()` and need a matching custom form in the step's folder in
 * apps/admin-page/src/routes/workflows/steps/ (e.g. Conditions/Criteria ↔ conditions/criteria/).
 *
 * Config life: `sanitize()` on every save (never fails) → `validate()` before Activate →
 * `run( Scope, config )`. A step reads data only through the Scope and returns a StepResult;
 * its outputs are exactly what `outputs()` declares.
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

    /** Plugin that provides the step (see Workflow/Extend/Plugins). */
    abstract public function plugin(): string;

    /** Module the step belongs to (see Workflow/Extend/Modules). Tiers live there, never in the step. */
    abstract public function module(): string;

    /** A name from `StepIcons::NAMES`. */
    abstract public function icon(): string;

    /**
     * The step's settings, in form order.
     *
     * @return Field[]
     */
    abstract public function fields(): array;

    /**
     * Card text with `%field key%` placeholders, e.g. "Change the order status to %status%".
     */
    abstract public function summary(): string;

    /**
     * What later steps can read at `steps.<id>.<key>`.
     *
     * @return array Key => [ 'label' => string, 'type' => Data\Type, 'options' => value => label (enums, optional) ].
     */
    public function outputs(): array {
        return [];
    }

    /**
     * Does the step's work and says how it went.
     *
     * @param Scope $scope  What the run can read; entities load on demand.
     * @param array $config Sanitized, valid config.
     */
    abstract public function run( Scope $scope, array $config ): StepResult;

    /** What happens to the run when this step fails: ON_ERROR_STOP or ON_ERROR_CONTINUE. */
    public function on_error(): string {
        return self::ON_ERROR_STOP;
    }

    /**
     * Coerces raw input into the stored config shape. Never fails; `validate()` decides if it's usable.
     * By default each field sanitizes its own value.
     *
     * @param mixed $config Raw config from the request.
     */
    public function sanitize( $config ): array {
        return Field::sanitize_all( $this->fields(), $config );
    }

    /**
     * Whether a sanitized config is complete enough to run. Required before a workflow can be active.
     * By default the fields' own rules; a step adds the checks fields can't express.
     *
     * @param array $config Output of `sanitize()`.
     * @return true|\WP_Error Error data holds the `field` it is about, when there is one.
     */
    public function validate( array $config ) {
        return $this->field_error( $config ) ?? true;
    }

    /**
     * Data items (see Workflow/Data) this step reads with the given config. The trigger must supply
     * them; otherwise the workflow gets a `missing_data` issue.
     *
     * @param array $config Output of `sanitize()`.
     */
    public function uses( array $config ): array {
        return [];
    }

    /**
     * Made-up outputs for a preview of a later step (an email that shows a coupon code): the step
     * itself never runs there. Empty = its outputs read empty in previews.
     *
     * @param array $config Output of `sanitize()`.
     */
    public function sample_outputs( array $config ): array {
        return [];
    }

    /** Extra facts for the admin app, merged into this step's catalog entry (`Definition\Catalog`). */
    public function client_meta(): array {
        return [];
    }

    /**
     * The fields' first broken rule as an error, or null.
     *
     * @param array $config Sanitized config.
     */
    protected function field_error( array $config ): ?\WP_Error {
        $error = Field::first_error( $this->fields(), $config, $this->label() );
        return $error ? $this->invalid( $error['message'], $error['field'] ) : null;
    }

    /**
     * @param string $message What's wrong.
     * @param string $field   Field key it is about, if any.
     */
    protected function invalid( string $message, string $field = '' ): \WP_Error {
        return new \WP_Error( 'yaymatik_invalid_config', $message, '' === $field ? '' : [ 'field' => $field ] );
    }

    /**
     * Raw input as trimmed text; '' for arrays and objects.
     *
     * @param mixed $value Raw input.
     */
    protected static function scalar( $value ): string {
        return is_scalar( $value ) ? trim( (string) $value ) : '';
    }
}
