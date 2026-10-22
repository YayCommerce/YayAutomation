<?php
namespace YayMatik\Workflow\Core\Data;

defined( 'ABSPATH' ) || exit;

/**
 * One kind of data a trigger can supply (order, customer, …); its fields are condition variables and merge tags.
 *
 * Field definition (`define()`):
 * - `label`       string
 * - `type`        TYPE_*: picks the condition operators
 * - `value_type`  Type, default from `type`; `entity` or `list<entity>` holds Refs (a relation)
 * - `value`       fn( $object ): mixed: float, `Y-m-d`, slug, bool, string, or Ref(s)
 * - `display`     fn( $object, bool $html ): string; default the value (an enum's option label)
 * - `options`     fn(): array value => label, enum only
 * - `conditional` bool, default true; false = display only
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
     * Preview values: field key => value, or [ 'value', 'text', 'html'? ] when it reads differently. Relations use their item's own.
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
                    'value_type'  => Type::from_condition_type( $field['type'] ),
                ];
            }
        }
        return $this->fields;
    }

    /**
     * Plain or safe HTML text of a field.
     *
     * @param array $field From `fields()`.
     * @param mixed $item_object
     * @param bool  $html
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
