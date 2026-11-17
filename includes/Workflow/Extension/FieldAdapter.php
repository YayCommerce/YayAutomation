<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Workflow\Core\Cart\SavedCart;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\DataItems\CartData;
use YayMatik\Workflow\DataItems\CustomerData;
use YayMatik\Workflow\DataItems\SiteData;

defined( 'ABSPATH' ) || exit;

/**
 * Public type names and data field arrays (docs/extension-api.md §4.2, §6) as core types and field
 * definitions. Every callback runs under `Guard`, gets the public object (`CustomerView`, `CartView`),
 * and its HTML is kses'd.
 *
 * @internal
 */
final class FieldAdapter {

    public const KEY = '/^[a-z][a-z0-9_]*$/';

    /** Fact types: one value, never an entity or a list. */
    public const SCALAR_TYPES = [ 'string', 'number', 'money', 'boolean', 'date', 'enum' ];

    private const CONDITION_TYPES = [
        'string'  => DataItem::TYPE_STRING,
        'number'  => DataItem::TYPE_NUMBER,
        'money'   => DataItem::TYPE_NUMBER,
        'boolean' => DataItem::TYPE_BOOLEAN,
        'date'    => DataItem::TYPE_DATE,
        'enum'    => DataItem::TYPE_ENUM,
        'list'    => DataItem::TYPE_LIST,
        'entity'  => DataItem::TYPE_STRING,
    ];

    /**
     * Core type from a type name: `list` reads `of`, `entity` reads `item`. Null when unknown.
     *
     * @param array $declared
     */
    public static function type( array $declared ): ?Type {
        switch ( $declared['type'] ?? null ) {
            case 'string':
                return Type::string();
            case 'number':
                return Type::from_condition_type( DataItem::TYPE_NUMBER );
            case 'money':
                return Type::money();
            case 'boolean':
                return Type::boolean();
            case 'date':
                return Type::date();
            case 'enum':
                return Type::enum();
            case 'list':
                $item = self::type(
                    [
                        'type' => $declared['of'] ?? 'string',
                        'item' => $declared['item'] ?? null,
                    ]
                );
                return $item ? Type::list_of( $item ) : null;
            case 'entity':
                return is_string( $declared['item'] ?? null ) && preg_match( self::KEY, $declared['item'] ) ? Type::entity( $declared['item'] ) : null;
        }
        return null;
    }

    /**
     * A data field definition, or why it was refused.
     *
     * @param string $module
     * @param string $item Data item the field is on.
     * @param string $key
     * @param mixed  $declared
     * @return array|string
     */
    public static function field( string $module, string $item, string $key, $declared ) {
        $type  = is_array( $declared ) ? self::type( $declared ) : null;
        $error = self::shape_error( $key, $declared, $type ) ?? ( is_callable( $declared['value'] ?? null ) ? null : "Field \"{$key}\" needs a callable \"value\"." );
        foreach ( [ 'display', 'timestamp' ] as $callback ) {
            if ( null === $error && isset( $declared[ $callback ] ) && ! is_callable( $declared[ $callback ] ) ) {
                $error = "Field \"{$key}\": \"{$callback}\" must be a callable.";
            }
        }
        if ( null !== $error ) {
            return $error;
        }
        $name     = "field {$item}.{$key}";
        $relation = $type->is( Type::ENTITY ) || ( $type->is( Type::LIST ) && 0 === strpos( $type->to_string(), 'list<entity' ) );
        $value    = $declared['value'];
        $field    = [
            'label'       => $declared['label'],
            'type'        => self::CONDITION_TYPES[ $declared['type'] ],
            'value_type'  => $type,
            'value'       => fn( $item_object ) => Guard::call( $module, $name, fn() => self::refs( $type, $value( self::view( $item, $item_object ) ) ), fn() => null ),
            'options'     => self::options( $module, $name, $declared, $type ),
            'conditional' => ! $relation && false !== ( $declared['conditional'] ?? true ),
            'merge_tag'   => false !== ( $declared['merge_tag'] ?? true ),
            'block'       => true === ( $declared['block'] ?? false ),
            'help'        => is_string( $declared['help'] ?? null ) ? $declared['help'] : null,
        ];
        if ( array_key_exists( 'sample', $declared ) ) {
            $field['sample'] = $declared['sample'];
        }
        if ( isset( $declared['display'] ) ) {
            $display          = $declared['display'];
            $field['display'] = fn( $item_object, bool $html ) => Guard::call(
                $module,
                $name,
                function () use ( $display, $item, $item_object, $html ) {
                    $text = (string) $display( self::view( $item, $item_object ), $html );
                    return $html ? wp_kses_post( $text ) : $text;
                },
                fn() => ''
            );
        }
        if ( isset( $declared['timestamp'] ) ) {
            $timestamp          = $declared['timestamp'];
            $field['timestamp'] = fn( $item_object ) => Guard::call(
                $module,
                $name,
                function () use ( $timestamp, $item, $item_object ) {
                    $at = $timestamp( self::view( $item, $item_object ) );
                    return is_int( $at ) ? $at : null;
                },
                fn() => null
            );
        }
        return $field;
    }

    /**
     * A trigger fact as a field definition (read as `trigger.<key>`), or why it was refused.
     *
     * @param string $module
     * @param string $key
     * @param mixed  $declared
     * @return array|string
     */
    public static function fact( string $module, string $key, $declared ) {
        $type  = is_array( $declared ) && in_array( $declared['type'] ?? null, self::SCALAR_TYPES, true ) ? self::type( $declared ) : null;
        $error = self::shape_error( $key, $declared, $type );
        if ( null !== $error ) {
            return str_replace( 'Field', 'Fact', $error );
        }
        return [
            'label'        => $declared['label'],
            'type'         => self::CONDITION_TYPES[ $declared['type'] ],
            'value_type'   => $type,
            'options'      => self::options( $module, "fact {$key}", $declared, $type ),
            'conditional'  => true,
            'merge_tag'    => true,
            'block'        => false,
            'display'      => null,
            'timestamp'    => null,
            'help'         => null,
            'search'       => null,
            'meta'         => null,
            'meta_blocked' => null,
        ];
    }

    /**
     * @param string    $key
     * @param mixed     $declared
     * @param Type|null $type
     */
    private static function shape_error( string $key, $declared, ?Type $type ): ?string {
        if ( ! preg_match( self::KEY, $key ) ) {
            return "Field key \"{$key}\" must be lowercase letters, digits and underscores, starting with a letter.";
        }
        if ( ! is_array( $declared ) || ! is_string( $declared['label'] ?? null ) || '' === trim( $declared['label'] ) || ! $type ) {
            return "Field \"{$key}\" needs a \"label\" and a known \"type\".";
        }
        if ( isset( $declared['options'] ) && ! is_array( $declared['options'] ) && ! is_callable( $declared['options'] ) ) {
            return "Field \"{$key}\": \"options\" must be an array or a callable.";
        }
        return null;
    }

    /**
     * An enum or list always has options, so display can look labels up.
     *
     * @param string $module
     * @param string $name
     * @param array  $declared
     * @param Type   $type
     */
    private static function options( string $module, string $name, array $declared, Type $type ): ?callable {
        $options = $declared['options'] ?? null;
        if ( null === $options ) {
            return $type->is( Type::ENUM ) || $type->is( Type::LIST ) ? fn() => [] : null;
        }
        return is_callable( $options )
            ? fn() => Guard::call( $module, $name, fn() => (array) $options(), fn() => [] )
            : fn() => $options;
    }

    /**
     * What a field callback gets for an item's object: core's internal customer and cart as views.
     *
     * @param string $item
     * @param mixed  $item_object
     * @return mixed
     */
    private static function view( string $item, $item_object ) {
        if ( SiteData::ID === $item ) {
            return null;
        }
        if ( CustomerData::ID === $item && is_object( $item_object ) ) {
            return new CustomerViewAdapter( $item_object );
        }
        if ( CartData::ID === $item && $item_object instanceof SavedCart ) {
            return new CartViewAdapter( $item_object );
        }
        return $item_object;
    }

    /**
     * An entity id (or list of them) as the Refs core reads relations by.
     *
     * @param Type  $type
     * @param mixed $value
     * @return mixed
     */
    private static function refs( Type $type, $value ) {
        if ( $type->is( Type::ENTITY ) ) {
            return is_scalar( $value ) && '' !== (string) $value ? self::ref( $type->entity_type(), (string) $value ) : null;
        }
        if ( 0 === strpos( $type->to_string(), 'list<entity<' ) ) {
            $item = substr( $type->to_string(), 12, -2 );
            return array_values( array_map( fn( $id ) => self::ref( $item, (string) $id ), array_filter( (array) $value, 'is_scalar' ) ) );
        }
        return $value;
    }

    /**
     * A customer is an account here (`user:<id>`), as core reads customers by source.
     *
     * @param string $item
     * @param string $id
     */
    private static function ref( string $item, string $id ): Ref {
        return new Ref( $item, CustomerData::ID === $item ? "user:{$id}" : $id );
    }
}
