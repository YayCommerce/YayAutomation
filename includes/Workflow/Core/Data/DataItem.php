<?php
namespace YayMatik\Workflow\Data;

defined( 'ABSPATH' ) || exit;

/**
 * One kind of data a trigger can supply (order, customer, …) and the fields read from it.
 * Fields are both condition variables and `{{ item.field }}` merge tags.
 *
 * Field definition (see `define()`):
 * - `label`       string
 * - `type`        one of the TYPE_* constants; picks the condition operators
 * - `value_type`  Type — the full type (money, entity<customer>, list<…>); defaults to `type`'s.
 *                 An `entity` or `list<entity>` field holds Refs: a relation, read through a Scope.
 * - `value`       fn( $object ): mixed — typed value for conditions (float, `Y-m-d`, slug, bool or
 *                 string), or Ref(s) for a relation
 * - `display`     fn( $object, bool $html ): string — merge tag text; defaults to the value (an enum's
 *                 option label). With `$html` it returns safe HTML, otherwise plain text.
 * - `options`     fn(): array value => label, enum fields only
 * - `conditional` false hides it from conditions (display-only fields); default true
 */
abstract class DataItem {

    public const TYPE_NUMBER  = 'number';
    public const TYPE_STRING  = 'string';
    public const TYPE_ENUM    = 'enum';
    public const TYPE_DATE    = 'date';
    public const TYPE_BOOLEAN = 'boolean';

    /** @var array[]|null */
    private $fields = null;

    abstract public function id(): string;

    abstract public function label(): string;

    /** One line for the "Insert variable" list. */
    abstract public function description(): string;

    /** Its tile in the "Insert variable" list: a name from `StepIcons::NAMES`. */
    public function icon(): string {
        return 'database';
    }

    /** @return array[] Field definitions keyed by field key. */
    abstract protected function define(): array;

    /** False for an entity reached only through a relation (`order.items`): no trigger supplies it. */
    public function standalone(): bool {
        return true;
    }

    /**
     * Made-up values for previews when there's no real data (labelled "Sample data"): field key =>
     * value, or [ 'value' => typed, 'text' => plain, 'html' => safe HTML (optional) ] when it reads
     * differently. Relations aren't listed: their item's own sample is used. Empty = none.
     */
    public function sample(): array {
        return [];
    }

    /** @return array[] Field definitions keyed by field key, with defaults filled in. */
    public function fields(): array {
        if ( null === $this->fields ) {
            $this->fields = [];
            foreach ( $this->define() as $key => $field ) {
                $this->fields[ $key ] = $field + [
                    'options'     => null,
                    'conditional' => true,
                    'display'     => null,
                    'value_type'  => Type::from_legacy( $field['type'] ),
                ];
            }
        }
        return $this->fields;
    }

    /**
     * Plain or HTML text of a field.
     *
     * @param array $field       From `fields()`.
     * @param mixed $item_object The item's object from the run context.
     * @param bool  $html        Return escaped HTML instead of plain text.
     */
    public static function display( array $field, $item_object, bool $html ): string {
        if ( $field['display'] ) {
            return (string) ( $field['display'] )( $item_object, $html );
        }
        $value = ( $field['value'] )( $item_object );
        // A relation (Ref or list of Refs) has no text of its own; its fields do.
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        $text = (string) $value;
        if ( is_bool( $value ) ) {
            $text = $value ? __( 'Yes', 'yaymatik' ) : __( 'No', 'yaymatik' );
        } elseif ( self::TYPE_ENUM === $field['type'] ) {
            $text = (string) ( ( $field['options'] )()[ $text ] ?? $text );
        }
        return $html ? esc_html( $text ) : $text;
    }
}
