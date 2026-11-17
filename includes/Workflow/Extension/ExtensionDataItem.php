<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Workflow\Core\Data\DataItem;

defined( 'ABSPATH' ) || exit;

/**
 * Another plugin's data item, from its declaration (docs/extension-api.md §6.2).
 *
 * @internal
 */
final class ExtensionDataItem extends DataItem {

    /** @var string */
    private $module;

    /** @var string */
    private $id;

    /** @var array From `Declaration::data_item()`. */
    private $args;

    /**
     * @param string $module
     * @param string $id
     * @param array  $args
     */
    public function __construct( string $module, string $id, array $args ) {
        $this->module = $module;
        $this->id     = $id;
        $this->args   = $args;
    }

    public function id(): string {
        return $this->id;
    }

    public function label(): string {
        return $this->args['label'];
    }

    public function description(): string {
        return $this->args['description'];
    }

    public function icon(): string {
        return $this->args['icon'] ?? parent::icon();
    }

    protected function define(): array {
        return $this->args['fields'];
    }

    public function sample(): array {
        return $this->args['sample'] ?? [];
    }

    /**
     * The plugin's object, or null when it is gone (or `load` throws).
     *
     * @param string $id
     * @return object|null
     */
    public function load( string $id ) {
        return Guard::call(
            $this->module,
            "load {$this->id}",
            function () use ( $id ) {
                $loaded = ( $this->args['load'] )( $id );
                return is_object( $loaded ) ? $loaded : null;
            },
            fn() => null
        );
    }
}
