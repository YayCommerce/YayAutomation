<?php
namespace YayMatik\Workflow\Core\Steps;

use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Type;

defined( 'ABSPATH' ) || exit;

/** Output `result` `yes` follows the `then` branch, `no` follows `otherwise`. */
abstract class Condition extends Step {

    public function kind(): string {
        return self::KIND_CONDITION;
    }

    /**
     * `result` (yes / no) picks the branch; declared here, so every condition's result survives the
     * Executor, which keeps declared outputs only.
     */
    public function outputs(): array {
        return [
            'result' => [
                'label'   => __( 'Result', 'yaymatik' ),
                'type'    => Type::enum(),
                'options' => [
                    'yes' => __( 'Yes', 'yaymatik' ),
                    'no'  => __( 'No', 'yaymatik' ),
                ],
            ],
        ];
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
