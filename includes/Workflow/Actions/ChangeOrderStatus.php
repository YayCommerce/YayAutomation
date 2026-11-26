<?php
namespace YayMatik\Workflow\Actions;

use YayMatik\Workflow\DataItems\OrderData;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Steps\Action;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\StepResult;
use YayMatik\Workflow\Triggers\OrderNoteAdded;
use YayMatik\Workflow\Triggers\OrderPaid;
use YayMatik\Workflow\Triggers\OrderRefunded;
use YayMatik\Workflow\Triggers\OrderStatusChanged;

defined( 'ABSPATH' ) || exit;

/** Moves the order to another status. */
class ChangeOrderStatus extends Action {

    public const ID = 'change_order_status';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Change order status', 'yaymatik-automation-for-woocommerce' );
    }

    public function description(): string {
        return __( 'Move the order to another status.', 'yaymatik-automation-for-woocommerce' );
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

    public function emits(): array {
        // WooCommerce adds a note for each status change, and refunds the rest when the status becomes Refunded.
        return [ OrderStatusChanged::ID, OrderPaid::ID, OrderRefunded::ID, OrderNoteAdded::ID ];
    }

    public function fields(): array {
        return [
            Field::select( 'status', __( 'New status', 'yaymatik-automation-for-woocommerce' ), [ OrderData::class, 'statuses' ] )
                ->required( __( 'Choose the new status.', 'yaymatik-automation-for-woocommerce' ) )
                ->placeholder( __( 'Choose a status', 'yaymatik-automation-for-woocommerce' ) ),
        ];
    }

    public function summary(): string {
        /* translators: {{ config.status }}: order status; keep {{ … }} as it is. */
        return __( 'Set to {{ config.status }}', 'yaymatik-automation-for-woocommerce' );
    }

    public function outputs(): array {
        $statuses = [ OrderData::class, 'statuses' ];
        return [
            'from' => [
                'label'   => __( 'Previous status', 'yaymatik-automation-for-woocommerce' ),
                'type'    => Type::enum(),
                'options' => $statuses,
            ],
            'to'   => [
                'label'   => __( 'New status', 'yaymatik-automation-for-woocommerce' ),
                'type'    => Type::enum(),
                'options' => $statuses,
            ],
        ];
    }

    /**
     * Statuses are stored without the `wc-` prefix, so it keeps its own sanitize.
     *
     * @param mixed $config
     */
    public function sanitize( $config ): array {
        $config = is_array( $config ) ? $config : [];

        return [ 'status' => OrderData::status_slug( self::scalar( $config['status'] ?? '' ) ) ];
    }

    /**
     * Setting the status the order already has changes nothing.
     *
     * @param Scope $scope
     * @param array $config
     */
    public function run( Scope $scope, array $config ): StepResult {
        $order = $scope->entity( OrderData::ID );
        if ( ! $order ) {
            return StepResult::skipped( __( 'There is no order to change.', 'yaymatik-automation-for-woocommerce' ) );
        }
        $from = $order->get_status();
        if ( $from !== $config['status'] ) {
            $order->update_status( $config['status'], __( 'Status changed by a YayMatik workflow.', 'yaymatik-automation-for-woocommerce' ) );
        }
        return StepResult::ok(
            [
                'from' => $from,
                'to'   => $config['status'],
            ]
        );
    }
}
