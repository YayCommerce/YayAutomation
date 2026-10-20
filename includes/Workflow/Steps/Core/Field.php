<?php
namespace YayMatik\Workflow\Steps\Core;

use YayMatik\Workflow\Definition\Catalog;
use YayMatik\Workflow\Definition\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * One setting of a step: its widget, rules and help. A step's `fields()` is the one source of its
 * settings: the admin app builds its form and checks from them (design §5).
 *
 * Kept small on purpose: a form that needs more than these widgets gets a custom panel, not a new
 * widget. `rules` (Condition rules builder) is for core steps only.
 *
 *     Field::text( 'key', __( 'Field name', 'yaymatik' ) )->required( __( '%s needs a field name.', 'yaymatik' ) )
 *     Field::select( 'type', __( 'Note type', 'yaymatik' ), $options )->default( 'private' )
 *
 * `sanitize()` never fails; `validate()` checks only the field's own rules. Template paths and
 * filters are checked by the workflow Validator, which knows the variables each step can use.
 */
final class Field {

    public const TEXT        = 'text';
    public const TEXTAREA    = 'textarea';
    public const HTML        = 'html';
    public const EMAILS      = 'emails';
    public const SELECT      = 'select';
    public const MULTISELECT = 'multiselect';
    public const TOGGLE      = 'toggle';
    public const NUMBER      = 'number';
    public const NUMBER_UNIT = 'number_unit';
    public const NOTICE      = 'notice';
    public const RULES       = 'rules';

    /** @var string */
    private $key;

    /** @var string One of the widget constants. */
    private $type;

    /** @var string */
    private $label;

    /** @var bool */
    private $required = false;

    /** @var string What a blank required field says; `%s` is the step's label. */
    private $required_message = '';

    /** @var bool Accepts merge tags. */
    private $variables = false;

    /** @var string */
    private $placeholder = '';

    /** @var string */
    private $help = '';

    /** @var array|null [ 'field' => key, 'value' => value or list of values ]. */
    private $show_if = null;

    /** @var mixed Value used when the input is missing or not usable. */
    private $default = null;

    /** @var array|callable|null Value => label; a callable is read when needed (e.g. order statuses). */
    private $options = null;

    /** @var int|float|null */
    private $min = null;

    /** @var int|float|null */
    private $max = null;

    /** @var string Notice text. */
    private $text = '';

    private function __construct( string $type, string $key, string $label ) {
        $this->type  = $type;
        $this->key   = $key;
        $this->label = $label;
    }

    /**
     * One line of text.
     *
     * @param string $key   Config key.
     * @param string $label Label in the form.
     */
    public static function text( string $key, string $label ): self {
        return ( new self( self::TEXT, $key, $label ) )->default( '' );
    }

    /**
     * Several lines of text.
     *
     * @param string $key   Config key.
     * @param string $label Label in the form.
     */
    public static function textarea( string $key, string $label ): self {
        return ( new self( self::TEXTAREA, $key, $label ) )->default( '' );
    }

    /**
     * Formatted text (the rich editor), stored as HTML cleaned by `wp_kses_post()`. Text without
     * HTML tags (saved before the field was rich) still works: it's read as plain text.
     *
     * @param string $key   Config key.
     * @param string $label Label in the form.
     */
    public static function html( string $key, string $label ): self {
        return ( new self( self::HTML, $key, $label ) )->default( '' );
    }

    /**
     * Email addresses, comma separated.
     *
     * @param string $key   Config key.
     * @param string $label Label in the form.
     */
    public static function emails( string $key, string $label ): self {
        return ( new self( self::EMAILS, $key, $label ) )->default( '' );
    }

    /**
     * One of a list. An empty list (e.g. no WooCommerce) accepts any value.
     *
     * @param string         $key   Config key.
     * @param string         $label Label in the form.
     * @param array|callable $options Value => label, or a callable that returns them.
     */
    public static function select( string $key, string $label, $options ): self {
        $field          = ( new self( self::SELECT, $key, $label ) )->default( '' );
        $field->options = $options;
        return $field;
    }

    /**
     * Any number of a list.
     *
     * @param string         $key   Config key.
     * @param string         $label Label in the form.
     * @param array|callable $options Value => label, or a callable that returns them.
     */
    public static function multiselect( string $key, string $label, $options ): self {
        $field          = ( new self( self::MULTISELECT, $key, $label ) )->default( [] );
        $field->options = $options;
        return $field;
    }

    /**
     * On or off.
     *
     * @param string $key   Config key.
     * @param string $label Label in the form.
     */
    public static function toggle( string $key, string $label ): self {
        return ( new self( self::TOGGLE, $key, $label ) )->default( false );
    }

    /**
     * A number; blank is null.
     *
     * @param string $key   Config key.
     * @param string $label Label in the form.
     */
    public static function number( string $key, string $label ): self {
        return new self( self::NUMBER, $key, $label );
    }

    /**
     * A number and its unit, e.g. 3 days. Stored as [ 'value' => number|null, 'unit' => unit ].
     *
     * @param string         $key   Config key.
     * @param string         $label Label in the form.
     * @param array|callable $units Unit => label; the first one is the default.
     */
    public static function number_unit( string $key, string $label, $units ): self {
        $field          = new self( self::NUMBER_UNIT, $key, $label );
        $field->options = $units;
        return $field;
    }

    /**
     * Read-only text in the form, e.g. who an email is sent from. Holds no value.
     *
     * @param string $key   Config key.
     * @param string $label Label in the form.
     * @param string $text  Text shown.
     */
    public static function notice( string $key, string $label, string $text ): self {
        $field       = new self( self::NOTICE, $key, $label );
        $field->text = $text;
        return $field;
    }

    /**
     * Condition rules builder, core steps only: a list of rules. Its step cleans and checks each
     * rule itself (`Criteria`); the field only makes sure the value is a list.
     *
     * @param string $key   Config key.
     * @param string $label Label in the form.
     */
    public static function rules( string $key, string $label ): self {
        return ( new self( self::RULES, $key, $label ) )->default( [] );
    }

    /**
     * Must not be blank. One message per field, naming the step: `%s needs a note.`, filled with
     * the step's label, so a renamed step never leaves a stale name in it.
     *
     * @param string $message Message with `%s` for the step's label; '' = a generic one.
     */
    public function required( string $message = '' ): self {
        $this->required         = true;
        $this->required_message = $message;
        return $this;
    }

    /** Accepts merge tags (`{{ order.number }}`); text keeps characters like `<` that tags may sit in. */
    public function variables(): self {
        $this->variables = true;
        return $this;
    }

    public function placeholder( string $placeholder ): self {
        $this->placeholder = $placeholder;
        return $this;
    }

    public function help( string $help ): self {
        $this->help = $help;
        return $this;
    }

    /**
     * Show the field only when another field has a value. A hidden field is never checked.
     *
     * @param string $field Other field's key.
     * @param mixed  $value Value, or a list of values, that shows this field.
     */
    public function show_if( string $field, $value ): self {
        $this->show_if = [
            'field' => $field,
            'value' => $value,
        ];
        return $this;
    }

    /**
     * @param mixed $value Value used when the input is missing or not usable.
     */
    public function default( $value ): self {
        $this->default = $value;
        return $this;
    }

    /**
     * @param int|float $min Smallest number allowed.
     */
    public function min( $min ): self {
        $this->min = $min;
        return $this;
    }

    /**
     * @param int|float $max Largest number allowed.
     */
    public function max( $max ): self {
        $this->max = $max;
        return $this;
    }

    public function key(): string {
        return $this->key;
    }

    public function type(): string {
        return $this->type;
    }

    public function label(): string {
        return $this->label;
    }

    /** Whether the field accepts merge tags (`variables()`). */
    public function accepts_variables(): bool {
        return $this->variables;
    }

    /** Whether the field is stored in the step's config (a notice isn't). */
    public function has_value(): bool {
        return self::NOTICE !== $this->type;
    }

    /** Value => label; [] when the field has no list or the list is empty. */
    public function options(): array {
        $options = is_callable( $this->options ) ? ( $this->options )() : $this->options;
        return is_array( $options ) ? $options : [];
    }

    /**
     * Coerces raw input into the stored shape. Never fails: unusable input becomes the default.
     *
     * @param mixed $raw Raw value from the request.
     * @return mixed
     */
    public function sanitize( $raw ) {
        switch ( $this->type ) {
            case self::TEXT:
                $text = self::scalar_text( $raw );
                return $this->variables ? self::single_line( $text ) : sanitize_text_field( $text );
            case self::TEXTAREA:
                $text = str_replace( "\r\n", "\n", self::scalar_text( $raw ) );
                return $this->variables ? self::plain_text( $text ) : sanitize_textarea_field( $text );
            case self::HTML:
                return wp_kses_post( self::plain_text( self::scalar_text( $raw ) ) );
            case self::EMAILS:
                return sanitize_text_field( self::scalar_text( $raw ) );
            case self::SELECT:
                return $this->sanitize_choice( $raw );
            case self::MULTISELECT:
                return $this->sanitize_choices( $raw );
            case self::TOGGLE:
                return null === $raw ? (bool) $this->default : ! empty( $raw );
            case self::NUMBER:
                return self::number_or_null( $raw ) ?? $this->default;
            case self::NUMBER_UNIT:
                return $this->sanitize_number_unit( $raw );
            case self::RULES:
                return is_array( $raw ) ? array_values( $raw ) : $this->default;
        }//end switch
        return null;
    }

    /**
     * Why a sanitized value can't be used, or null when it can. Checks only this field's rules.
     *
     * @param mixed  $value Output of `sanitize()`.
     * @param array  $config The whole sanitized config, for `show_if`.
     * @param string $step   Label of the step the field belongs to, for the required message.
     */
    public function validate( $value, array $config = [], string $step = '' ): ?string {
        if ( ! $this->has_value() || ! $this->visible( $config ) ) {
            return null;
        }
        if ( $this->is_blank( $value ) ) {
            return $this->required ? $this->required_text( $step ) : null;
        }
        switch ( $this->type ) {
            case self::EMAILS:
                foreach ( self::split_emails( $value ) as $email ) {
                    if ( ! ( $this->variables && false !== strpos( $email, '{{' ) ) && ! is_email( $email ) ) {
                        return Messages::get( 'invalidEmail', $email );
                    }
                }
                return null;
            case self::SELECT:
                return $this->known( [ $value ] ) ? null : Messages::get( 'chooseOption' );
            case self::MULTISELECT:
                return $this->known( $value ) ? null : Messages::get( 'chooseOptions' );
            case self::NUMBER:
                return $this->range_error( $value, $step );
            case self::NUMBER_UNIT:
                return $this->range_error( $value['value'], $step );
        }
        return null;
    }

    /**
     * Whether the field shows for this config (`show_if`).
     *
     * @param array $config Sanitized config.
     */
    public function visible( array $config ): bool {
        if ( ! $this->show_if ) {
            return true;
        }
        $wanted = (array) $this->show_if['value'];
        return in_array( $config[ $this->show_if['field'] ] ?? null, $wanted, true );
    }

    /** Shape for the admin app (`yayMatikMeta.automation`): only keys that are set. */
    public function to_array(): array {
        $data = [
            'key'   => $this->key,
            'type'  => $this->type,
            'label' => $this->label,
        ];
        if ( $this->required ) {
            $data['required'] = true;
        }
        if ( '' !== $this->required_message ) {
            $data['requiredMessage'] = $this->required_message;
        }
        if ( $this->variables ) {
            $data['variables'] = true;
        }
        if ( '' !== $this->placeholder ) {
            $data['placeholder'] = $this->placeholder;
        }
        if ( '' !== $this->help ) {
            $data['help'] = $this->help;
        }
        if ( $this->show_if ) {
            $data['showIf'] = $this->show_if;
        }
        if ( null !== $this->options ) {
            $data['options'] = Catalog::options( $this->options() );
        }
        if ( $this->has_value() ) {
            // Text keeps its default as written (a new step's sample subject): a missing value on
            // save stays blank, so it can't turn into the sample.
            $data['default'] = in_array( $this->type, [ self::TEXT, self::TEXTAREA, self::HTML ], true )
                ? (string) $this->default
                : $this->sanitize( null );
        }
        if ( null !== $this->min ) {
            $data['min'] = $this->min;
        }
        if ( null !== $this->max ) {
            $data['max'] = $this->max;
        }
        if ( self::NOTICE === $this->type ) {
            $data['text'] = $this->text;
        }
        return $data;
    }

    /**
     * Config from raw input: each field with a value, sanitized. Keys not in the fields are dropped.
     *
     * @param Field[] $fields Step fields.
     * @param mixed   $raw    Raw config from the request.
     */
    public static function sanitize_all( array $fields, $raw ): array {
        $raw    = is_array( $raw ) ? $raw : [];
        $config = [];
        foreach ( $fields as $field ) {
            if ( $field->has_value() ) {
                $config[ $field->key() ] = $field->sanitize( $raw[ $field->key() ] ?? null );
            }
        }
        return $config;
    }

    /**
     * First field whose value can't be used, in field order, or null.
     *
     * @param Field[] $fields Step fields.
     * @param array   $config Output of `sanitize_all()`.
     * @param string  $step   The step's label, for required messages.
     * @return array|null [ 'field' => key, 'message' => text ].
     */
    public static function first_error( array $fields, array $config, string $step = '' ): ?array {
        foreach ( $fields as $field ) {
            $message = $field->validate( $config[ $field->key() ] ?? null, $config, $step );
            if ( null !== $message ) {
                return [
                    'field'   => $field->key(),
                    'message' => $message,
                ];
            }
        }
        return null;
    }

    /**
     * Cleans text that may hold merge tags. Not sanitize_text(area)_field(): those treat
     * `<{{ customer.email }}>` as a tag and strip it. Output is escaped where it's used.
     *
     * @param string $text Raw text.
     */
    public static function plain_text( string $text ): string {
        $text = str_replace( "\r\n", "\n", wp_check_invalid_utf8( $text ) );
        // Control characters, keeping tab and newline.
        return trim( (string) preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/', '', $text ) );
    }

    /**
     * `plain_text()` on one line: line breaks and tabs become spaces (e.g. so a subject can't inject mail headers).
     *
     * @param string $text Raw text.
     */
    public static function single_line( string $text ): string {
        return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', self::plain_text( $text ) ) );
    }

    /**
     * Comma-separated emails as a list, blanks dropped.
     *
     * @param string $emails Text from an `emails` field.
     */
    public static function split_emails( string $emails ): array {
        return array_values( array_filter( array_map( 'trim', explode( ',', $emails ) ) ) );
    }

    /**
     * @param mixed $raw Raw value.
     */
    private function sanitize_choice( $raw ): string {
        $value = self::scalar_text( $raw );
        $value = '' === $value ? '' : sanitize_text_field( $value );
        if ( '' === $value || ! $this->known( [ $value ] ) ) {
            // Unknown values stay when there's no default, so validate() can reject them.
            return null !== $this->default && '' !== $this->default ? (string) $this->default : $value;
        }
        return $value;
    }

    /**
     * @param mixed $raw Raw value.
     */
    private function sanitize_choices( $raw ): array {
        $values = [];
        foreach ( is_array( $raw ) ? $raw : (array) $this->default as $value ) {
            $value = sanitize_text_field( self::scalar_text( $value ) );
            if ( '' !== $value ) {
                $values[] = $value;
            }
        }
        return array_values( array_unique( $values ) );
    }

    /**
     * @param mixed $raw Raw value.
     */
    private function sanitize_number_unit( $raw ): array {
        $raw   = is_array( $raw ) ? $raw : [];
        $units = array_map( 'strval', array_keys( $this->options() ) );
        $unit  = self::scalar_text( $raw['unit'] ?? '' );
        $value = self::number_or_null( $raw['value'] ?? null );
        if ( null === $value && is_array( $this->default ) ) {
            $value = self::number_or_null( $this->default['value'] ?? null );
        }
        return [
            'value' => $value,
            'unit'  => in_array( $unit, $units, true ) ? $unit : (string) ( $units[0] ?? '' ),
        ];
    }

    /**
     * Whether all values are in the list. An empty list accepts anything (nothing to check against).
     *
     * @param array $values Values.
     */
    private function known( array $values ): bool {
        $known = array_map( 'strval', array_keys( $this->options() ) );
        return ! $known || ! array_diff( array_map( 'strval', $values ), $known );
    }

    /**
     * The required message, with the step's label filled in.
     *
     * @param string $step Step label.
     */
    private function required_text( string $step ): string {
        return '' === $this->required_message
            ? Messages::get( 'fieldRequired' )
            : sprintf( $this->required_message, $step );
    }

    /**
     * @param int|float|null $number Number.
     * @param string         $step   Step label, for the required message.
     */
    private function range_error( $number, string $step ): ?string {
        if ( null === $number ) {
            return $this->required ? $this->required_text( $step ) : null;
        }
        if ( null !== $this->min && $number < $this->min ) {
            return Messages::get( 'numberMin', $this->min );
        }
        if ( null !== $this->max && $number > $this->max ) {
            return Messages::get( 'numberMax', $this->max );
        }
        return null;
    }

    /**
     * @param mixed $value Sanitized value.
     */
    private function is_blank( $value ): bool {
        if ( self::NUMBER_UNIT === $this->type ) {
            return null === ( $value['value'] ?? null );
        }
        if ( self::RULES === $this->type ) {
            // Rules with no variable chosen yet don't count.
            return ! array_filter( array_column( array_filter( (array) $value, 'is_array' ), 'field' ) );
        }
        if ( self::TOGGLE === $this->type ) {
            return false;
        }
        if ( self::HTML === $this->type ) {
            // An emptied rich editor leaves `<p>&nbsp;</p>`; an image alone still counts.
            return ! preg_match( '/<img\b/i', (string) $value )
                && '' === trim( str_replace( [ '&nbsp;', "\xC2\xA0" ], ' ', wp_strip_all_tags( (string) $value ) ) );
        }
        return null === $value || '' === $value || [] === $value;
    }

    /**
     * @param mixed $raw Raw value.
     * @return int|float|null
     */
    private static function number_or_null( $raw ) {
        if ( is_bool( $raw ) || ! is_numeric( is_string( $raw ) ? trim( $raw ) : $raw ) ) {
            return null;
        }
        return 0 + ( is_string( $raw ) ? trim( $raw ) : $raw );
    }

    /**
     * @param mixed $raw Raw value.
     */
    private static function scalar_text( $raw ): string {
        return is_scalar( $raw ) && ! is_bool( $raw ) ? (string) $raw : '';
    }
}
