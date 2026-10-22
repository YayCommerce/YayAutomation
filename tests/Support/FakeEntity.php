<?php
namespace YayMatik\Tests\Support;

use YayMatik\Workflow\Core\Data\DataItem;

/** An entity type whose fields are given, read from plain arrays instead of WooCommerce objects. */
final class FakeEntity extends DataItem {

    /** @var string */
    private $entity_id;

    /** @var array[] */
    private $definitions;

    /**
     * @param string  $entity_id
     * @param array[] $definitions As `DataItem::define()` returns them.
     */
    public function __construct( string $entity_id, array $definitions ) {
        $this->entity_id   = $entity_id;
        $this->definitions = $definitions;
    }

    public function id(): string {
        return $this->entity_id;
    }

    public function label(): string {
        return $this->entity_id;
    }

    public function description(): string {
        return '';
    }

    protected function define(): array {
        return $this->definitions;
    }
}
