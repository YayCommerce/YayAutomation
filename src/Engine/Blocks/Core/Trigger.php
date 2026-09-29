<?php
namespace YayAutomation\Engine\Blocks\Core;

defined( 'ABSPATH' ) || exit;

/** The step that starts a workflow. */
abstract class Trigger extends Block {

    public function kind(): string {
        return self::KIND_TRIGGER;
    }

    abstract public function matches( array $context, array $config ): bool;
}
