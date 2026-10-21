<?php
namespace YayMatik\Workflow\Core\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Points at an entity instead of holding it: `order` + `12`.
 */
final class Ref {

    public const JSON_KEY = '__ref';

    /** @var string */
    private $type;

    /** @var string */
    private $id;

    /**
     * @param string     $type Entity type id.
     * @param string|int $id   A customer is `order:34` (billing details, guests too) or `user:12`.
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

    /** `order:12`. */
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
     * @param mixed $value
     */
    public static function from_json( $value ): ?self {
        if ( ! is_array( $value ) || 1 !== count( $value ) || ! isset( $value[ self::JSON_KEY ]['type'], $value[ self::JSON_KEY ]['id'] ) ) {
            return null;
        }
        return new self( (string) $value[ self::JSON_KEY ]['type'], (string) $value[ self::JSON_KEY ]['id'] );
    }
}
