<?php
namespace YayAutomation\Engine\Blocks\Core;

defined( 'ABSPATH' ) || exit;

/** A step that does something, e.g. sends an email. */
abstract class Action extends Block {

    public function kind(): string {
        return self::KIND_ACTION;
    }

    /**
     * Performs the step. A WP_Error is logged; the workflow continues with the next step.
     *
     * @param array $context Run context, see Block.
     * @param array $config  Sanitized, valid config.
     * @return true|\WP_Error
     */
    abstract public function run( array $context, array $config );
}
