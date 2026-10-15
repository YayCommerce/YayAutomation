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

/** Config: [ 'key' => meta key, 'value' => string with merge tags ]. An empty value is stored as is. */
class UpdateOrderMeta extends Action {

    public const ID = 'update_order_meta';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Update order custom field', 'yay-automation' );
    }

    public function description(): string {
        return __( 'Set a custom field (meta) on the order.', 'yay-automation' );
    }

    public function plugin(): string {
        return Plugins::WOOCOMMERCE;
    }

    public function module(): string {
        return Modules::CORE;
    }

    public function icon(): string {
        return 'list';
    }

    public function category(): string {
        return self::CATEGORY_ORDER;
    }

    public function requires(): array {
        return [ OrderData::ID ];
    }

    public function fields(): array {
        return [
            Field::text( 'key', __( 'Field name', 'yay-automation' ) )
                /* translators: %s: step name, e.g. "Update order custom field" */
                ->required( __( '%s needs a field name.', 'yay-automation' ) )
                ->help( __( 'The meta key, e.g. _vip_order.', 'yay-automation' ) ),
            Field::text( 'value', __( 'Value', 'yay-automation' ) )->variables(),
        ];
    }

    public function summary(): string {
        /* translators: %key%: custom field name, %value%: its value; keep the placeholders as they are. */
        return __( 'Set %key% to %value%', 'yay-automation' );
    }

    public function outputs(): array {
        return [
            'key' => [
                'label' => __( 'Field name', 'yay-automation' ),
                'type'  => Type::string(),
            ],
        ];
    }

    public function run( Scope $scope, array $config ): StepResult {
        $order = $scope->entity( OrderData::ID );
        if ( ! $order ) {
            return StepResult::skipped( __( 'There is no order to update.', 'yay-automation' ) );
        }
        $order->update_meta_data( $config['key'], self::render( $config['value'], $scope ) );
        $order->save();
        return StepResult::ok( [ 'key' => $config['key'] ] );
    }
}
