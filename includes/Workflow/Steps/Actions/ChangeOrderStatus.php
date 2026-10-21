<?php
namespace YayMatik\Workflow\Steps\Actions;

use YayMatik\Workflow\Data\OrderData;
use YayMatik\Workflow\Data\Scope;
use YayMatik\Workflow\Data\Type;
use YayMatik\Workflow\Modules\WoocommerceModule;
use YayMatik\Workflow\Steps\Core\Action;
use YayMatik\Workflow\Steps\Core\Field;
use YayMatik\Workflow\Steps\Core\StepResult;

defined( 'ABSPATH' ) || exit;

/** Config: [ 'status' => slug without the `wc-` prefix ]. */
class ChangeOrderStatus extends Action {

    public const ID = 'change_order_status';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Change order status', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Move the order to another status.', 'yaymatik' );
    }

    public function plugin(): string {
        return WoocommerceModule::ID;
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
            Field::select( 'status', __( 'New status', 'yaymatik' ), [ OrderData::class, 'statuses' ] )
                ->required( __( 'Choose the new status.', 'yaymatik' ) )
                ->placeholder( __( 'Choose a status', 'yaymatik' ) ),
        ];
    }

    public function summary(): string {
        /* translators: %status%: order status; keep the placeholder as it is. */
        return __( 'Set to %status%', 'yaymatik' );
    }

    public function outputs(): array {
        $statuses = [ OrderData::class, 'statuses' ];
        return [
            'from' => [
                'label'   => __( 'Previous status', 'yaymatik' ),
                'type'    => Type::enum(),
                'options' => $statuses,
            ],
            'to'   => [
                'label'   => __( 'New status', 'yaymatik' ),
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
            return StepResult::skipped( __( 'There is no order to change.', 'yaymatik' ) );
        }
        $from = $order->get_status();
        if ( $from !== $config['status'] ) {
            $order->update_status( $config['status'], __( 'Status changed by a YayMatik workflow.', 'yaymatik' ) );
        }
        return StepResult::ok(
            [
                'from' => $from,
                'to'   => $config['status'],
            ]
        );
    }
}
