<?php
namespace YayMatik\Workflow\Core\Data;

defined( 'ABSPATH' ) || exit;

/**
 * The type of a value in a workflow, written as text in the catalog: `money`, `entity<order>`, `list<entity<order_item>>`.
 *
 * Conditions compare by the condition field types (`DataItem::TYPE_*`).
 */
final class Type {

    public const STRING  = 'string';
    public const NUMBER  = 'number';
    public const BOOLEAN = 'boolean';
    public const DATE    = 'date';
    public const MONEY   = 'money';
    public const ENUM    = 'enum';
    public const ENTITY  = 'entity';
    public const LIST    = 'list';

    /** @var string */
    private $name;

    /** @var string|null */
    private $entity;

    /** @var Type|null */
    private $item;

    private function __construct( string $name, ?string $entity = null, ?Type $item = null ) {
        $this->name   = $name;
        $this->entity = $entity;
        $this->item   = $item;
    }

    public static function string(): self {
        return new self( self::STRING );
    }

    public static function boolean(): self {
        return new self( self::BOOLEAN );
    }

    public static function date(): self {
        return new self( self::DATE );
    }

    public static function money(): self {
        return new self( self::MONEY );
    }

    public static function enum(): self {
        return new self( self::ENUM );
    }

    /**
     * A thing with its own fields, loaded by ref: `entity<order>`.
     *
     * @param string $entity Entity type id.
     */
    public static function entity( string $entity ): self {
        return new self( self::ENTITY, $entity );
    }

    /**
     * Several values of one type: `list<entity<order_item>>`.
     *
     * @param Type $item
     */
    public static function list_of( Type $item ): self {
        return new self( self::LIST, null, $item );
    }

    /** Entity type id of an `entity` type, else null. */
    public function entity_type(): ?string {
        return $this->entity;
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
     * The full type for a field that states only its condition type.
     *
     * @param string $type One of `DataItem::TYPE_*`.
     */
    public static function from_condition_type( string $type ): self {
        $map = [
            DataItem::TYPE_NUMBER  => self::NUMBER,
            DataItem::TYPE_DATE    => self::DATE,
            DataItem::TYPE_ENUM    => self::ENUM,
            DataItem::TYPE_BOOLEAN => self::BOOLEAN,
        ];
        return new self( $map[ $type ] ?? self::STRING );
    }
}
