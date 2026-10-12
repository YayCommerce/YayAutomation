<?php
namespace YayAutomation\Workflow\Steps\Core;

use YayAutomation\Workflow\Data\Scope;

defined( 'ABSPATH' ) || exit;

/** Output `result` `yes` follows the `then` branch, `no` follows `otherwise`. */
abstract class Condition extends Step {

    public function kind(): string {
        return self::KIND_CONDITION;
    }

    /**
     * Whether the config's rules match.
     *
     * @param Scope $scope  Run scope.
     * @param array $config Sanitized, valid config.
     */
    abstract public function evaluate( Scope $scope, array $config ): bool;

    /**
     * `ok( [ 'result' => 'yes'|'no' ] )`.
     *
     * @param Scope $scope  Run scope.
     * @param array $config Sanitized, valid config.
     */
    public function run( Scope $scope, array $config ): StepResult {
        return StepResult::ok( [ 'result' => $this->evaluate( $scope, $config ) ? 'yes' : 'no' ] );
    }
}
