<?php
namespace YayAutomation\Engine\Data;

defined( 'ABSPATH' ) || exit;

/**
 * One kind of data a trigger can supply (order, customer, …) and the fields read from it.
 * Fields are both condition variables and `{{ item.field }}` merge tags.
 *
 * Field definition (see `define()`):
 * - `label`       string
 * - `type`        one of the TYPE_* constants; picks the condition operators
 * - `value`       fn( $object ): mixed — typed value for conditions (float, `Y-m-d`, slug or string)
 * - `display`     fn( $object, bool $html ): string — merge tag text; defaults to the value (an enum's
 *                 option label). With `$html` it returns safe HTML, otherwise plain text.
 * - `options`     fn(): array value => label, enum fields only
 * - `conditional` false hides it from conditions (display-only fields); default true
 */
abstract class DataItem {

    public const TYPE_NUMBER = 'number';
    public const TYPE_STRING = 'string';
    public const TYPE_ENUM   = 'enum';
    public const TYPE_DATE   = 'date';

    /** @var array[]|null */
    private $fields = null;

    abstract public function id(): string;

    abstract public function label(): string;

    /** @return array[] Field definitions keyed by field key. */
    abstract protected function define(): array;

    /** @return array[] Field definitions keyed by field key, with defaults filled in. */
    public function fields(): array {
        if ( null === $this->fields ) {
            $this->fields = [];
            foreach ( $this->define() as $key => $field ) {
                $this->fields[ $key ] = $field + [
                    'options'     => null,
                    'conditional' => true,
                    'display'     => null,
                ];
            }
        }
        return $this->fields;
    }

    /**
     * Plain or HTML text of a field.
     *
     * @param array  $field  From `fields()`.
     * @param mixed  $object The item's object from the run context.
     * @param bool   $html   Return escaped HTML instead of plain text.
     */
    public static function display( array $field, $object, bool $html ): string {
        if ( $field['display'] ) {
            return (string) ( $field['display'] )( $object, $html );
        }
        $text = (string) ( $field['value'] )( $object );
        if ( self::TYPE_ENUM === $field['type'] ) {
            $text = (string) ( ( $field['options'] )()[ $text ] ?? $text );
        }
        return $html ? esc_html( $text ) : $text;
    }
}
