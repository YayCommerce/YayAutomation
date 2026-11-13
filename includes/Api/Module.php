<?php
namespace YayMatik\Api;

use YayMatik\Workflow\Extension\ExtensionModule;

defined( 'ABSPATH' ) || exit;

/** A plugin's module; a refused one accepts every call and does nothing. */
final class Module {

    /** @var ExtensionModule|null Null when refused. */
    private $module;

    /**
     * @param ExtensionModule|null $module
     */
    private function __construct( ?ExtensionModule $module ) {
        $this->module = $module;
    }

    public function ok(): bool {
        return null !== $this->module;
    }

    /**
     * @param string $id
     * @param array  $args
     */
    public function trigger( string $id, array $args ): bool {
        return $this->module ? $this->module->add_trigger( $id, $args ) : false;
    }

    /**
     * @param string $id
     * @param array  $args
     */
    public function action( string $id, array $args ): bool {
        return $this->module ? $this->module->add_action( $id, $args ) : false;
    }

    /**
     * A picker section for this module's steps.
     *
     * @param string $id
     * @param string $label
     */
    public function category( string $id, string $label ): bool {
        return $this->module ? $this->module->add_category( $id, $label ) : false;
    }
}
