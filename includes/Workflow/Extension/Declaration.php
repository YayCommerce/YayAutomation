<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Api\Api;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Steps\Action;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\Step;
use YayMatik\Workflow\Core\Steps\StepIcons;

defined( 'ABSPATH' ) || exit;

/**
 * Checks what an extension declares (docs/extension-api.md §2, §5). A bad declaration is refused
 * with a diagnostic; unknown keys are ignored.
 *
 * @internal
 */
final class Declaration {

    private const ID = '/^[a-z][a-z0-9_]{2,63}$/';

    private const KEY = '/^[a-z_][a-z0-9_]*$/';

    private const RESERVED = [ 'yaymatik', 'woocommerce', 'wordpress', 'core', 'steps', 'trigger', 'run', 'site' ];

    private const TRIGGER_CATEGORIES = [ 'order', 'cart', 'customer', 'review' ];

    private const ACTION_CATEGORIES = [ Action::CATEGORY_EMAIL, Action::CATEGORY_ORDER, Action::CATEGORY_COUPON, Action::CATEGORY_CUSTOMER, Action::CATEGORY_FLOW ];

    /** Data items a `yaymatik_fire` payload can give (`RefMap`). */
    private const FIRED_ITEMS = [ 'customer', 'order', 'cart', 'review' ];

    /** @var string[]|null Data item ids, read once. */
    private static $items = null;

    private const MODULE_KEYS = [ 'id', 'label', 'description', 'logo', 'requires_api', 'privacy_subjects' ];

    private const STEP_KEYS = [ 'label', 'description', 'icon', 'category', 'fields', 'summary', 'validate', 'setup_issue' ];

    private const TRIGGER_KEYS = [ 'provides', 'matches', 'once_per', 'facts', 'sample' ];

    private const DATA_ITEM_KEYS = [ 'label', 'description', 'icon', 'load', 'fields', 'sample' ];

    private const DATA_FIELD_KEYS = [ 'label', 'type', 'of', 'item', 'value', 'display', 'options', 'help', 'timestamp', 'merge_tag', 'conditional', 'block', 'sample' ];

    private const MAX_FACTS = 10;

    private const ACTION_KEYS = [ 'requires', 'run', 'outputs', 'sample_outputs', 'on_error', 'result', 'emits', 'email' ];

    private const EMAIL_KEYS = [ 'audience', 'recipient', 'build' ];

    /** Field type => whether its third argument (`options`, `units` or `text`) is required. */
    private const FIELD_TYPES = [
        Field::TEXT        => '',
        Field::TEXTAREA    => '',
        Field::HTML        => '',
        Field::NUMBER      => '',
        Field::SELECT      => 'options',
        Field::MULTISELECT => 'options',
        Field::TOGGLE      => '',
        Field::EMAILS      => '',
        Field::NOTICE      => 'text',
        Field::NUMBER_UNIT => 'units',
    ];

    /**
     * The module, or null when refused.
     *
     * @param array    $args
     * @param string[] $taken Module ids registered so far.
     * @param callable $open  fn(): bool — false once `yaymatik_register` has run.
     */
    public static function module( array $args, array $taken, callable $open ): ?ExtensionModule {
        $id    = is_string( $args['id'] ?? null ) ? $args['id'] : '';
        $error = self::module_error( $id, $args, $taken );
        if ( null !== $error ) {
            self::refuse( '' === $id ? '(unknown)' : $id, 'module', $error );
            return null;
        }
        self::unknown_keys( $id, 'module', $args, self::MODULE_KEYS );
        return new ExtensionModule(
            $id,
            $args['label'],
            is_string( $args['description'] ?? null ) ? $args['description'] : '',
            is_string( $args['logo'] ?? null ) ? $args['logo'] : '',
            $args['requires_api'] ?? null,
            $open,
            $args['privacy_subjects'] ?? null
        );
    }

    /**
     * Normalized trigger args, or null when refused.
     *
     * @param ExtensionModule $module
     * @param string          $id
     * @param array           $args
     */
    public static function trigger( ExtensionModule $module, string $id, array $args ): ?array {
        $what  = "trigger {$id}";
        $error = self::step_error( $module, $id, $args, self::TRIGGER_CATEGORIES )
            ?? self::list_error( $args, 'provides', true )
            ?? self::callable_error( $args, [], [ 'matches' ] );
        $fired = array_merge( self::FIRED_ITEMS, array_keys( $module->data_items() ) );
        if ( null === $error && ( ! $args['provides'] || array_diff( $args['provides'], $fired ) ) ) {
            $error = '"provides" must list data items a payload gives: ' . implode( ', ', $fired ) . ' (declare a data item before the trigger that provides it).';
        }
        $facts = null === $error ? self::facts( $module, $args['facts'] ?? [] ) : null;
        if ( null === $error && is_string( $facts ) ) {
            $error = $facts;
        }
        if ( null === $error && isset( $args['sample'] ) && ! is_array( $args['sample'] ) ) {
            $error = '"sample" must be fact => value.';
        }
        if ( null === $error && isset( $args['once_per'] ) && 'subject' !== $args['once_per'] ) {
            $error = '"once_per" must be "subject".';
        }
        $fields = null === $error ? self::fields( $module->id(), $args['fields'] ?? [] ) : null;
        if ( null !== $error || is_string( $fields ) ) {
            self::refuse( $module->id(), $what, $error ?? $fields );
            return null;
        }
        self::unknown_keys( $module->id(), $what, $args, array_merge( self::STEP_KEYS, self::TRIGGER_KEYS ) );
        return [
            'fields' => $fields,
            'facts'  => $facts,
            'items'  => fn() => array_keys( $module->data_items() ),
        ] + $args;
    }

    /**
     * Normalized action args, or null when refused.
     *
     * @param ExtensionModule $module
     * @param string          $id
     * @param array           $args
     */
    public static function action( ExtensionModule $module, string $id, array $args ): ?array {
        $what  = "action {$id}";
        $error = self::step_error( $module, $id, $args, self::ACTION_CATEGORIES )
            ?? self::list_error( $args, 'requires', false )
            ?? self::items_error( $args['requires'] ?? [] )
            ?? self::list_error( $args, 'emits', false )
            ?? ( isset( $args['email'] ) ? self::email_error( $args ) : self::callable_error( $args, [ 'run' ], [ 'sample_outputs' ] ) );
        if ( null === $error && isset( $args['on_error'] ) && ! in_array( $args['on_error'], [ Step::ON_ERROR_STOP, Step::ON_ERROR_CONTINUE ], true ) ) {
            $error = '"on_error" must be "stop" or "continue".';
        }
        if ( null === $error && isset( $args['result'] ) && ! is_string( $args['result'] ) ) {
            $error = '"result" must be a string.';
        }
        $fields  = null === $error ? self::fields( $module->id(), $args['fields'] ?? [] ) : null;
        $outputs = null === $error && ! is_string( $fields ) ? self::outputs( $module->id(), $args['outputs'] ?? [] ) : null;
        $error   = $error ?? ( is_string( $fields ) ? $fields : null ) ?? ( is_string( $outputs ) ? $outputs : null );
        if ( null === $error && isset( $args['email'] ) && 'customer' !== $args['email']['recipient'] && ! self::has_field( $fields, $args['email']['recipient'] ) ) {
            $error = '"email" "recipient" must be "customer" or a key of "fields".';
        }
        if ( null !== $error ) {
            self::refuse( $module->id(), $what, $error );
            return null;
        }
        self::unknown_keys( $module->id(), $what, $args, array_merge( self::STEP_KEYS, self::ACTION_KEYS ) );
        if ( isset( $args['email'] ) ) {
            self::unknown_keys( $module->id(), "{$what} email", $args['email'], self::EMAIL_KEYS );
            $args['email'] += [ 'audience' => 'customer' ];
        }
        return [
            'fields'  => $fields,
            'outputs' => $outputs,
        ] + $args;
    }

    /**
     * An email action: core runs it, so it has no `run` or `outputs` of its own (docs/extension-api.md §5.5).
     *
     * @param array $args
     */
    private static function email_error( array $args ): ?string {
        $email = $args['email'];
        if ( ! is_array( $email ) ) {
            return '"email" must be [ audience, recipient, build ].';
        }
        foreach ( [ 'run', 'outputs', 'sample_outputs' ] as $key ) {
            if ( isset( $args[ $key ] ) ) {
                return "An email action has no \"{$key}\": core sends it and outputs \"to\".";
            }
        }
        if ( isset( $email['audience'] ) && ! in_array( $email['audience'], [ 'customer', 'staff' ], true ) ) {
            return '"email" "audience" must be "customer" or "staff".';
        }
        if ( ! is_string( $email['recipient'] ?? null ) ) {
            return '"email" needs "recipient": "customer" or a field key.';
        }
        return is_callable( $email['build'] ?? null ) ? null : '"email" "build" must be a callable.';
    }

    /**
     * @param Field[] $fields
     * @param string  $key
     */
    private static function has_field( array $fields, string $key ): bool {
        foreach ( $fields as $field ) {
            if ( $field->key() === $key ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalized data item args, or null when refused.
     *
     * @param ExtensionModule $module
     * @param string          $id
     * @param array           $args
     */
    public static function data_item( ExtensionModule $module, string $id, array $args ): ?array {
        $what  = "data item {$id}";
        $error = self::own_id_error( $module->id(), $id );
        foreach ( [ 'label', 'description' ] as $key ) {
            if ( null === $error && ( ! is_string( $args[ $key ] ?? null ) || '' === trim( $args[ $key ] ) ) ) {
                $error = "\"{$key}\" is required.";
            }
        }
        if ( null === $error && isset( $args['icon'] ) && ! in_array( $args['icon'], StepIcons::NAMES, true ) ) {
            $error = '"icon" must be a name from YayMatik\'s step icon list.';
        }
        if ( null === $error && in_array( $id, self::item_ids(), true ) ) {
            $error = "Data item id \"{$id}\" is already taken.";
        }
        $error  = $error ?? self::callable_error( $args, [ 'load' ], [] );
        $fields = null === $error ? self::data_field_defs( $module, $id, $args['fields'] ?? [], false ) : null;
        if ( null === $error && isset( $args['sample'] ) && ! is_array( $args['sample'] ) ) {
            $error = '"sample" must be field => value.';
        }
        $error = $error ?? ( is_string( $fields ) ? $fields : null );
        if ( null !== $error ) {
            self::refuse( $module->id(), $what, $error );
            return null;
        }
        self::unknown_keys( $module->id(), $what, $args, self::DATA_ITEM_KEYS );
        return [ 'fields' => $fields ] + $args;
    }

    /**
     * Field definitions to add to a data item, or null when refused.
     *
     * @param ExtensionModule $module
     * @param string          $item
     * @param mixed           $fields
     */
    public static function data_fields( ExtensionModule $module, string $item, $fields ): ?array {
        $what  = "data fields {$item}";
        $error = in_array( $item, self::item_ids(), true ) || isset( $module->data_items()[ $item ] ) ? null : "Unknown data item \"{$item}\".";
        $defs  = null === $error ? self::data_field_defs( $module, $item, $fields, true ) : null;
        $error = $error ?? ( is_string( $defs ) ? $defs : null );
        if ( null !== $error ) {
            self::refuse( $module->id(), $what, $error );
            return null;
        }
        return $defs;
    }

    /**
     * Whether the module may declare this category; refused with a diagnostic otherwise.
     *
     * @param ExtensionModule $module
     * @param string          $id
     * @param string          $label
     */
    public static function category( ExtensionModule $module, string $id, string $label ): bool {
        $error = self::own_id_error( $module->id(), $id ) ?? ( '' === trim( $label ) ? 'A category needs a label.' : null );
        if ( null !== $error ) {
            self::refuse( $module->id(), "category {$id}", $error );
            return false;
        }
        return true;
    }

    /**
     * A call made after `yaymatik_register` has run.
     *
     * @param string $module
     * @param string $what
     */
    public static function too_late( string $module, string $what ): void {
        self::refuse( $module, $what, 'Called after yaymatik_register has run; register inside its callback.' );
    }

    /**
     * Records why a declaration was refused; also `_doing_it_wrong()` under WP_DEBUG.
     *
     * @param string $module
     * @param string $what
     * @param string $message
     */
    public static function refuse( string $module, string $what, string $message ): void {
        Diagnostics::record( $module, $what, $message );
        self::doing_it_wrong( "{$module}, {$what}: {$message}" );
    }

    /**
     * @param string   $id
     * @param array    $args
     * @param string[] $taken
     */
    private static function module_error( string $id, array $args, array $taken ): ?string {
        if ( ! preg_match( self::ID, $id ) ) {
            return 'A module id is 3–64 lowercase letters, digits or underscores, starting with a letter.';
        }
        if ( in_array( $id, self::RESERVED, true ) ) {
            return "\"{$id}\" is reserved for YayMatik.";
        }
        if ( in_array( $id, $taken, true ) ) {
            return "Module id \"{$id}\" is already taken by another module.";
        }
        if ( ! is_string( $args['label'] ?? null ) || '' === trim( $args['label'] ) ) {
            return 'A module needs a label.';
        }
        if ( isset( $args['privacy_subjects'] ) && ! is_callable( $args['privacy_subjects'] ) ) {
            return '"privacy_subjects" must be a callable.';
        }
        if ( isset( $args['requires_api'] ) && ! self::api_met( $args['requires_api'] ) ) {
            return 'Needs YayMatik with Extension API ' . ( is_scalar( $args['requires_api'] ) ? (string) $args['requires_api'] : '?' ) . '.';
        }
        return null;
    }

    /**
     * Same MAJOR, and a MINOR not newer than ours.
     *
     * @param mixed $required
     */
    private static function api_met( $required ): bool {
        if ( ! is_string( $required ) || ! preg_match( '/^(\d+)\.(\d+)(\.\d+)?$/', $required, $want ) ) {
            return false;
        }
        list( $major, $minor ) = array_map( 'intval', explode( '.', Api::VERSION ) );
        return (int) $want[1] === $major && (int) $want[2] <= $minor;
    }

    /**
     * @param ExtensionModule $module
     * @param string          $id
     * @param array           $args
     * @param string[]        $categories Core categories of the kind.
     */
    private static function step_error( ExtensionModule $module, string $id, array $args, array $categories ): ?string {
        $error = self::own_id_error( $module->id(), $id );
        if ( null !== $error ) {
            return $error;
        }
        foreach ( [ 'label', 'description' ] as $key ) {
            if ( ! is_string( $args[ $key ] ?? null ) || '' === trim( $args[ $key ] ) ) {
                return "\"{$key}\" is required.";
            }
        }
        if ( isset( $args['icon'] ) && ! in_array( $args['icon'], StepIcons::NAMES, true ) ) {
            return '"icon" must be a name from YayMatik\'s step icon list.';
        }
        if ( isset( $args['category'] ) && ! in_array( $args['category'], $categories, true ) && ! isset( $module->categories()[ $args['category'] ] ) ) {
            return '"category" must be a core category or one this module declared first.';
        }
        if ( isset( $args['summary'] ) && ! is_string( $args['summary'] ) ) {
            return '"summary" must be a string.';
        }
        return self::callable_error( $args, [], [ 'validate', 'setup_issue' ] );
    }

    /**
     * @param string $module
     * @param string $id
     */
    private static function own_id_error( string $module, string $id ): ?string {
        if ( ! preg_match( self::ID, $id ) || 0 !== strpos( $id, $module . '_' ) ) {
            return "An id must start with \"{$module}_\" and be 3–64 lowercase letters, digits or underscores.";
        }
        return null;
    }

    /**
     * @param array  $args
     * @param string $key
     * @param bool   $required
     */
    private static function list_error( array $args, string $key, bool $required ): ?string {
        if ( ! isset( $args[ $key ] ) ) {
            return $required ? "\"{$key}\" is required." : null;
        }
        $list = $args[ $key ];
        return is_array( $list ) && array_values( $list ) === $list && count( array_filter( $list, 'is_string' ) ) === count( $list )
            ? null
            : "\"{$key}\" must be a list of ids.";
    }

    /**
     * @param string[] $requires
     */
    private static function items_error( array $requires ): ?string {
        $unknown = array_diff( $requires, self::item_ids() );
        return $unknown ? 'Unknown data item in "requires": ' . implode( ', ', $unknown ) . '.' : null;
    }

    /**
     * @param array    $args
     * @param string[] $required
     * @param string[] $optional
     */
    private static function callable_error( array $args, array $required, array $optional ): ?string {
        foreach ( $required as $key ) {
            if ( ! is_callable( $args[ $key ] ?? null ) ) {
                return "\"{$key}\" must be a callable.";
            }
        }
        foreach ( $optional as $key ) {
            if ( isset( $args[ $key ] ) && ! is_callable( $args[ $key ] ) ) {
                return "\"{$key}\" must be a callable.";
            }
        }
        return null;
    }

    /**
     * Core fields from `key => [ type, label, … ]` (§4.1), or why they were refused.
     *
     * @param string $module
     * @param mixed  $fields
     * @return Field[]|string
     */
    private static function fields( string $module, $fields ) {
        if ( ! is_array( $fields ) ) {
            return '"fields" must be key => field array.';
        }
        $built = [];
        foreach ( $fields as $key => $field ) {
            if ( ! is_string( $key ) || ! preg_match( self::KEY, $key ) ) {
                return "Field key \"{$key}\" must be lowercase letters, digits or underscores.";
            }
            $core = is_array( $field ) ? self::core_field( $module, $key, $field ) : "Field \"{$key}\" must be an array.";
            if ( is_string( $core ) ) {
                return $core;
            }
            $built[] = $core;
        }
        return $built;
    }

    /**
     * @param string $module
     * @param string $key
     * @param array  $field
     * @return Field|string
     */
    private static function core_field( string $module, string $key, array $field ) {
        $type  = $field['type'] ?? null;
        $label = $field['label'] ?? null;
        if ( ! is_string( $type ) || ! isset( self::FIELD_TYPES[ $type ] ) ) {
            return "Field \"{$key}\" needs a \"type\" from: " . implode( ', ', array_keys( self::FIELD_TYPES ) ) . '.';
        }
        if ( ! is_string( $label ) || '' === trim( $label ) ) {
            return "Field \"{$key}\" needs a \"label\".";
        }
        $args  = [ $key, $label ];
        $extra = self::FIELD_TYPES[ $type ];
        if ( '' !== $extra ) {
            $value = $field[ $extra ] ?? null;
            if ( 'text' === $extra ? ! is_string( $value ) : ! is_array( $value ) && ! is_callable( $value ) ) {
                return "Field \"{$key}\" needs \"{$extra}\".";
            }
            $args[] = 'text' !== $extra && is_callable( $value ) ? self::guarded_options( $module, $value ) : $value;
        }
        $core = Field::$type( ...$args );
        if ( ! empty( $field['required'] ) ) {
            $core->required( is_string( $field['required'] ) ? $field['required'] : '' );
        }
        if ( true === ( $field['variables'] ?? false ) ) {
            $core->variables();
        }
        foreach ( [ 'help', 'placeholder' ] as $text ) {
            if ( isset( $field[ $text ] ) ) {
                $core->$text( (string) $field[ $text ] );
            }
        }
        foreach ( [ 'min', 'max' ] as $limit ) {
            if ( isset( $field[ $limit ] ) ) {
                if ( ! is_int( $field[ $limit ] ) && ! is_float( $field[ $limit ] ) ) {
                    return "Field \"{$key}\": \"{$limit}\" must be a number.";
                }
                $core->$limit( $field[ $limit ] );
            }
        }
        if ( array_key_exists( 'default', $field ) ) {
            $core->default( $field['default'] );
        }
        if ( isset( $field['show_if'] ) ) {
            if ( ! is_array( $field['show_if'] ) || 1 !== count( $field['show_if'] ) || ! is_string( key( $field['show_if'] ) ) ) {
                return "Field \"{$key}\": \"show_if\" must be [ field => value ].";
            }
            $core->show_if( key( $field['show_if'] ), current( $field['show_if'] ) );
        }
        return $core;
    }

    /**
     * @param ExtensionModule $module
     * @param string          $item
     * @param mixed           $fields
     * @param bool            $prefixed Keys must start with the module id (fields on another item).
     * @return array|string
     */
    private static function data_field_defs( ExtensionModule $module, string $item, $fields, bool $prefixed ) {
        if ( ! is_array( $fields ) ) {
            return '"fields" must be key => field array.';
        }
        $defs = [];
        foreach ( $fields as $key => $field ) {
            $key = (string) $key;
            if ( $prefixed && 0 !== strpos( $key, $module->id() . '_' ) ) {
                return "Field key \"{$key}\" must start with \"{$module->id()}_\".";
            }
            $def = FieldAdapter::field( $module->id(), $item, $key, $field );
            if ( is_string( $def ) ) {
                return $def;
            }
            self::unknown_keys( $module->id(), "field {$item}.{$key}", $field, self::DATA_FIELD_KEYS );
            $defs[ $key ] = $def;
        }
        return $defs;
    }

    /**
     * Fact definitions, or why they were refused.
     *
     * @param ExtensionModule $module
     * @param mixed           $facts
     * @return array|string
     */
    private static function facts( ExtensionModule $module, $facts ) {
        if ( ! is_array( $facts ) || count( $facts ) > self::MAX_FACTS ) {
            return '"facts" must be key => [ label, type ], at most ' . self::MAX_FACTS . '.';
        }
        $defs = [];
        foreach ( $facts as $key => $fact ) {
            $key = (string) $key;
            if ( '_id' === substr( $key, -3 ) || in_array( $key, array_merge( self::FIRED_ITEMS, array_keys( $module->data_items() ) ), true ) ) {
                return "Fact key \"{$key}\" is a payload id key or a data item id.";
            }
            $def = FieldAdapter::fact( $module->id(), $key, $fact );
            if ( is_string( $def ) ) {
                return $def . ' Facts are one value: ' . implode( ', ', FieldAdapter::SCALAR_TYPES ) . '.';
            }
            $defs[ $key ] = $def;
        }
        return $defs;
    }

    /** @return string[] Ids of the data items of the class-based modules, read once. */
    private static function item_ids(): array {
        self::$items = self::$items ?? array_keys( DataRegistry::items() );
        return self::$items;
    }

    /**
     * Core outputs, or why they were refused.
     *
     * @param string $module
     * @param mixed  $outputs
     * @return array|string
     */
    private static function outputs( string $module, $outputs ) {
        if ( ! is_array( $outputs ) ) {
            return '"outputs" must be key => [ label, type ].';
        }
        $built = [];
        foreach ( $outputs as $key => $output ) {
            $type = is_array( $output ) ? FieldAdapter::type( $output ) : null;
            if ( ! is_string( $key ) || ! preg_match( self::KEY, $key ) || ! is_string( $output['label'] ?? null ) || ! $type ) {
                return "Output \"{$key}\" must be lowercase key => [ label, type ], with a known type name.";
            }
            $built[ $key ] = [
                'label' => $output['label'],
                'type'  => $type,
            ];
            if ( isset( $output['options'] ) ) {
                $built[ $key ]['options'] = is_callable( $output['options'] ) ? self::guarded_options( $module, $output['options'] ) : (array) $output['options'];
            }
        }
        return $built;
    }

    /**
     * @param string   $module
     * @param callable $options
     */
    private static function guarded_options( string $module, callable $options ): callable {
        return fn() => Guard::call( $module, 'options', fn() => (array) $options(), fn() => [] );
    }

    /**
     * @param string   $module
     * @param string   $what
     * @param array    $args
     * @param string[] $known
     */
    private static function unknown_keys( string $module, string $what, array $args, array $known ): void {
        $unknown = array_diff( array_keys( $args ), $known );
        if ( $unknown ) {
            self::doing_it_wrong( "{$module}, {$what}: unknown keys ignored: " . implode( ', ', $unknown ) . '.' );
        }
    }

    /**
     * @param string $message
     */
    private static function doing_it_wrong( string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( '_doing_it_wrong' ) ) {
            _doing_it_wrong( 'yaymatik_register', esc_html( $message ), esc_html( Api::VERSION ) );
        }
    }
}
