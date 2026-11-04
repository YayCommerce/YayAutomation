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
 * - `value`       fn( $object ): mixed: float, `Y-m-d`, slug, bool, string, string[] (list), or Ref(s)
 * - `display`     fn( $object, bool $html ): string; default the value (an enum's option label)
 * - `options`     fn(): array value => label, enum and list
 * - `timestamp`   fn( $object ): ?int, the moment behind a date, for "in the last N hours"; without it a date counts as 00:00 store time
 * - `meta`        fn( $object, string $key ): string, a custom field; for `TYPE_META`, which compares key + value
 * - `meta_blocked` fn( string $key ): bool, keys a rule can't read
 * - `merge_tag`   bool, default true; false = not offered by "Insert variable" and not a known tag
 * - `conditional` bool, default true; false = display only
 * - `block`       bool, default false; a ready-made email block (HTML display), inserted on its own line
 */
abstract class DataItem {

    public const TYPE_NUMBER  = 'number';
    public const TYPE_STRING  = 'string';
    public const TYPE_ENUM    = 'enum';
    public const TYPE_DATE    = 'date';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_LIST    = 'list';
    public const TYPE_META    = 'meta';

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
            /**
             * Filters one data item's raw field definitions before defaults are applied.
             *
             * The dynamic portion is the data item id, for example
             * `yaymatik_data_item_site_fields`.
             *
             * @param array[]  $definitions Field definitions keyed by field key.
             * @param DataItem $item        The data item being built.
             */
            $definitions  = (array) apply_filters( "yaymatik_data_item_{$this->id()}_fields", $this->define(), $this );
            $this->fields = [];
            foreach ( $definitions as $key => $field ) {
                $this->fields[ $key ] = $field + [
                    'options'      => null,
                    'conditional'  => true,
                    'block'        => false,
                    'display'      => null,
                    'timestamp'    => null,
                    'meta'         => null,
                    'meta_blocked' => null,
                    'merge_tag'    => true,
                    'value_type'   => Type::from_condition_type( $field['type'] ),
                ];
            }
        }
        return $this->fields;
    }

    /**
     * Plain text, or the same escaped for HTML.
     *
     * @param string $text
     * @param bool   $html
     */
    protected static function text( string $text, bool $html ): string {
        return $html ? esc_html( $text ) : $text;
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
