<?php
namespace YayMatik\Api;

defined( 'ABSPATH' ) || exit;

/** What `yaymatik_register` passes: the way a plugin adds a module. */
final class Registrar {

    /** @var callable fn( array $args ): Module */
    private $module;

    /**
     * @param callable $module
     */
    private function __construct( callable $module ) {
        $this->module = $module;
    }

    /**
     * Always a handle; a refused module's handle does nothing (`ok()` false).
     *
     * @param array $args
     */
    public function module( array $args ): Module {
        return ( $this->module )( $args );
    }

    public function api_version(): string {
        return Api::VERSION;
    }
}
