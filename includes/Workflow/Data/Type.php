<?php
namespace YayAutomation\Workflow\Data;

defined( 'ABSPATH' ) || exit;

/**
 * The type of a value in a workflow: a field, a step output, a variable. Written as text in the
 * catalog: `money`, `entity<order>`, `list<entity<order_item>>`.
 *
 * Conditions still compare by the older field types (number, string, enum, date, boolean);
 * `legacy()` maps each type onto them.
 */
final class Type {

    public const STRING   = 'string';
    public const NUMBER   = 'number';
    public const BOOLEAN  = 'boolean';
    public const DATE     = 'date';
    public const DATETIME = 'datetime';
    public const MONEY    = 'money';
    public const ENUM     = 'enum';
    public const ENTITY   = 'entity';
    public const LIST     = 'list';
    public const OBJECT   = 'object';

    private const SCALARS = [ self::STRING, self::NUMBER, self::BOOLEAN, self::DATE, self::DATETIME, self::MONEY, self::ENUM, self::OBJECT ];

    /** @var string */
    private $name;

    /** @var string|null Entity type id, for `entity`. */
    private $entity;

    /** @var Type|null Item type, for `list`. */
    private $item;

    private function __construct( string $name, ?string $entity = null, ?Type $item = null ) {
        $this->name   = $name;
        $this->entity = $entity;
        $this->item   = $item;
    }

    public static function string(): self {
        return new self( self::STRING );
    }

    public static function number(): self {
        return new self( self::NUMBER );
    }

    public static function boolean(): self {
        return new self( self::BOOLEAN );
    }

    public static function date(): self {
        return new self( self::DATE );
    }

    public static function datetime(): self {
        return new self( self::DATETIME );
    }

    public static function money(): self {
        return new self( self::MONEY );
    }

    public static function enum(): self {
        return new self( self::ENUM );
    }

    public static function object(): self {
        return new self( self::OBJECT );
    }

    /**
     * A thing with its own fields, loaded by ref: `entity<order>`.
     *
     * @param string $entity Entity type id, e.g. `order`.
     */
    public static function entity( string $entity ): self {
        return new self( self::ENTITY, $entity );
    }

    /**
     * Several values of one type: `list<entity<order_item>>`.
     *
     * @param Type $item Type of each item.
     */
    public static function list_of( Type $item ): self {
        return new self( self::LIST, null, $item );
    }

    /**
     * Parses the text form. Null when it isn't a type.
     *
     * @param string $text E.g. `list<entity<order_item>>`.
     */
    public static function parse( string $text ): ?self {
        $text = trim( $text );
        if ( in_array( $text, self::SCALARS, true ) ) {
            return new self( $text );
        }
        if ( preg_match( '/^entity<([a-z_][a-z0-9_]*)>$/', $text, $match ) ) {
            return self::entity( $match[1] );
        }
        if ( preg_match( '/^list<(.+)>$/', $text, $match ) ) {
            $item = self::parse( $match[1] );
            return $item ? self::list_of( $item ) : null;
        }
        return null;
    }

    public function name(): string {
        return $this->name;
    }

    /** Entity type id of an `entity` type, else null. */
    public function entity_type(): ?string {
        return $this->entity;
    }

    /** Item type of a `list`, else null. */
    public function item(): ?Type {
        return $this->item;
    }

    public function is( string $name ): bool {
        return $this->name === $name;
    }

    /** Text form, as the catalog and the admin app see it. */
    public function to_string(): string {
        if ( self::ENTITY === $this->name ) {
            return 'entity<' . $this->entity . '>';
        }
        if ( self::LIST === $this->name ) {
            return 'list<' . $this->item->to_string() . '>';
        }
        return $this->name;
    }

    /**
     * The field type conditions compare by: money → number, datetime → date, anything without its
     * own comparisons → string.
     */
    public function legacy(): string {
        switch ( $this->name ) {
            case self::NUMBER:
            case self::MONEY:
                return DataItem::TYPE_NUMBER;
            case self::DATE:
            case self::DATETIME:
                return DataItem::TYPE_DATE;
            case self::ENUM:
                return DataItem::TYPE_ENUM;
            case self::BOOLEAN:
                return DataItem::TYPE_BOOLEAN;
        }
        return DataItem::TYPE_STRING;
    }

    /**
     * The full type for a field that only states an older field type.
     *
     * @param string $legacy One of `DataItem::TYPE_*`.
     */
    public static function from_legacy( string $legacy ): self {
        $map = [
            DataItem::TYPE_NUMBER  => self::NUMBER,
            DataItem::TYPE_DATE    => self::DATE,
            DataItem::TYPE_ENUM    => self::ENUM,
            DataItem::TYPE_BOOLEAN => self::BOOLEAN,
        ];
        return new self( $map[ $legacy ] ?? self::STRING );
    }
}
