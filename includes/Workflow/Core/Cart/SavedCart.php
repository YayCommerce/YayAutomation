<?php
namespace YayMatik\Workflow\Core\Cart;

use YayMatik\Workflow\Core\Storage\CartStore;

defined( 'ABSPATH' ) || exit;

/** One `carts` row as an object: `Scope` only reads objects from the resolver. */
final class SavedCart {

    /** @var array */
    private $row;

    /**
     * @param array $row
     */
    public function __construct( array $row ) {
        $this->row = $row;
    }

    /**
     * @param int $id
     */
    public static function find( int $id ): ?self {
        $row = CartStore::find( $id );
        return $row ? new self( $row ) : null;
    }

    public function id(): int {
        return (int) $this->row['id'];
    }

    public function user_id(): int {
        return (int) $this->row['user_id'];
    }

    public function email(): string {
        return (string) $this->row['email'];
    }

    public function first_name(): string {
        return (string) $this->row['first_name'];
    }

    /** @return array[] Saved lines; empty on bad JSON. */
    public function lines(): array {
        $lines = json_decode( (string) $this->row['items'], true );
        return is_array( $lines ) ? $lines : [];
    }

    public function total(): float {
        return (float) $this->row['total'];
    }

    public function currency(): string {
        return (string) $this->row['currency'];
    }

    /** GMT datetime. */
    public function last_activity(): string {
        return (string) $this->row['last_activity_at'];
    }
}
