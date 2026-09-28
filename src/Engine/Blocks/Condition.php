<?php
namespace YayAutomation\Engine\Blocks;

defined( 'ABSPATH' ) || exit;

/** True follows the `then` branch, false follows `otherwise`. */
abstract class Condition extends Block {

    public function kind(): string {
        return self::KIND_CONDITION;
    }

    abstract public function evaluate( array $context, array $config ): bool;
}
