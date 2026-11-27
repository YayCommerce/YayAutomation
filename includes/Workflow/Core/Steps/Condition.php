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

    /** Declared here so the result survives the Executor, which keeps declared outputs only. */
    public function outputs(): array {
        return [
            'result' => [
                'label'   => __( 'Result', 'yaymatik-automation-for-woocommerce' ),
                'type'    => Type::enum(),
                'options' => [
                    'yes' => __( 'Yes', 'yaymatik-automation-for-woocommerce' ),
                    'no'  => __( 'No', 'yaymatik-automation-for-woocommerce' ),
                ],
            ],
        ];
    }

    /**
     * Whether the config's rules match.
     *
     * @param Scope $scope
     * @param array $config
     */
    abstract public function evaluate( Scope $scope, array $config ): bool;

    public function run( Scope $scope, array $config ): StepResult {
        return StepResult::ok( [ 'result' => $this->evaluate( $scope, $config ) ? 'yes' : 'no' ] );
    }
}
