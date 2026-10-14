<?php
namespace YayAutomation\Workflow\Steps\Actions;

use YayAutomation\Workflow\Data\OrderData;
use YayAutomation\Workflow\Data\Scope;
use YayAutomation\Workflow\Data\Type;
use YayAutomation\Workflow\Extend\Modules;
use YayAutomation\Workflow\Extend\Plugins;
use YayAutomation\Workflow\Steps\Core\Action;
use YayAutomation\Workflow\Steps\Core\Field;
use YayAutomation\Workflow\Steps\Core\StepResult;

defined( 'ABSPATH' ) || exit;

/** Config: [ 'status' => slug without the `wc-` prefix ]. */
class ChangeOrderStatus extends Action {

    public const ID = 'change_order_status';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Change order status', 'yay-automation' );
    }

    public function description(): string {
        return __( 'Move the order to another status.', 'yay-automation' );
    }

    public function plugin(): string {
        return Plugins::WOOCOMMERCE;
    }

    public function module(): string {
        return Modules::CORE;
    }

    public function icon(): string {
        return 'tag';
    }

    public function category(): string {
        return self::CATEGORY_ORDER;
    }

    public function requires(): array {
        return [ OrderData::ID ];
    }

    public function fields(): array {
        return [
            Field::select( 'status', __( 'New status', 'yay-automation' ), [ OrderData::class, 'statuses' ] )
                ->required( __( 'Choose the new status.', 'yay-automation' ) )
                ->placeholder( __( 'Choose a status', 'yay-automation' ) ),
        ];
    }

    public function summary(): string {
        /* translators: %status%: order status; keep the placeholder as it is. */
        return __( 'Set to %status%', 'yay-automation' );
    }

    public function outputs(): array {
        $statuses = [ OrderData::class, 'statuses' ];
        return [
            'from' => [
                'label'   => __( 'Previous status', 'yay-automation' ),
                'type'    => Type::enum(),
                'options' => $statuses,
            ],
            'to'   => [
                'label'   => __( 'New status', 'yay-automation' ),
                'type'    => Type::enum(),
                'options' => $statuses,
            ],
        ];
    }

    /**
     * Statuses are stored without the `wc-` prefix, so it keeps its own sanitize.
     *
     * @param mixed $config Raw config from the request.
     */
    public function sanitize( $config ): array {
        $config = is_array( $config ) ? $config : [];

        return [ 'status' => OrderData::status_slug( self::scalar( $config['status'] ?? '' ) ) ];
    }

    /**
     * Setting the status the order already has changes nothing.
     *
     * @param Scope $scope  Run scope.
     * @param array $config Sanitized, valid config.
     */
    public function run( Scope $scope, array $config ): StepResult {
        $order = $scope->entity( OrderData::ID );
        if ( ! $order ) {
            return StepResult::skipped( __( 'There is no order to change.', 'yay-automation' ) );
        }
        $from = $order->get_status();
        if ( $from !== $config['status'] ) {
            $order->update_status( $config['status'], __( 'Status changed by a YayAutomation workflow.', 'yay-automation' ) );
        }
        return StepResult::ok(
            [
                'from' => $from,
                'to'   => $config['status'],
            ]
        );
    }
}
