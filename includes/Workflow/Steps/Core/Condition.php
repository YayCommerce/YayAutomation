<?php
namespace YayAutomation\Workflow\Steps\Core;

defined( 'ABSPATH' ) || exit;

/** True follows the `then` branch, false follows `otherwise`. */
abstract class Condition extends Step {

    public function kind(): string {
        return self::KIND_CONDITION;
    }

    abstract public function evaluate( array $context, array $config ): bool;
}
