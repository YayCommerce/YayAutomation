<?php
namespace YayMatik\Workflow\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Points at an entity instead of holding it: `order` + `12`. A run stores refs and loads the entity
 * when a step reads it, so the data is current after a long wait and the stored row stays small.
 *
 * Ids are strings. A customer is `order:34` (the order's billing details, works for guests) or
 * `user:12` (an account).
 */
final class Ref {

    /** Key that marks a ref in stored JSON. */
    public const JSON_KEY = '__ref';

    /** @var string */
    private $type;

    /** @var string */
    private $id;

    /**
     * @param string     $type Entity type id, e.g. `order`.
     * @param string|int $id   Entity id.
     */
    public function __construct( string $type, $id ) {
        $this->type = $type;
        $this->id   = (string) $id;
    }

    public function type(): string {
        return $this->type;
    }

    public function id(): string {
        return $this->id;
    }

    /** `order:12`, for logs and messages. */
    public function key(): string {
        return $this->type . ':' . $this->id;
    }

    /** Stored form: `[ '__ref' => [ 'type' => 'order', 'id' => '12' ] ]`. */
    public function to_json(): array {
        return [
            self::JSON_KEY => [
                'type' => $this->type,
                'id'   => $this->id,
            ],
        ];
    }

    /**
     * The ref a stored value holds, or null when it isn't one.
     *
     * @param mixed $value Decoded JSON value.
     */
    public static function from_json( $value ): ?self {
        if ( ! is_array( $value ) || 1 !== count( $value ) || ! isset( $value[ self::JSON_KEY ]['type'], $value[ self::JSON_KEY ]['id'] ) ) {
            return null;
        }
        return new self( (string) $value[ self::JSON_KEY ]['type'], (string) $value[ self::JSON_KEY ]['id'] );
    }
}
