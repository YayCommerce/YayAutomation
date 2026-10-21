<?php
namespace YayMatik\Workflow\Steps\Actions;

use YayMatik\Workflow\Data\OrderData;
use YayMatik\Workflow\Data\Scope;
use YayMatik\Workflow\Data\Type;
use YayMatik\Workflow\Extend\Modules;
use YayMatik\Workflow\Extend\Plugins;
use YayMatik\Workflow\Steps\Core\Action;
use YayMatik\Workflow\Steps\Core\Field;
use YayMatik\Workflow\Steps\Core\StepResult;

defined( 'ABSPATH' ) || exit;

/** Config: [ 'key' => meta key, 'value' => string with merge tags ]. An empty value is stored as is. */
class UpdateOrderMeta extends Action {

    public const ID = 'update_order_meta';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Update order custom field', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Set a custom field (meta) on the order.', 'yaymatik' );
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
            Field::text( 'key', __( 'Field name', 'yaymatik' ) )
                /* translators: %s: step name, e.g. "Update order custom field" */
                ->required( __( '%s needs a field name.', 'yaymatik' ) )
                ->help( __( 'The meta key, e.g. _vip_order.', 'yaymatik' ) ),
            Field::text( 'value', __( 'Value', 'yaymatik' ) )->variables(),
        ];
    }

    public function summary(): string {
        /* translators: %key%: custom field name, %value%: its value; keep the placeholders as they are. */
        return __( 'Set %key% to %value%', 'yaymatik' );
    }

    public function outputs(): array {
        return [
            'key' => [
                'label' => __( 'Field name', 'yaymatik' ),
                'type'  => Type::string(),
            ],
        ];
    }

    public function run( Scope $scope, array $config ): StepResult {
        $order = $scope->entity( OrderData::ID );
        if ( ! $order ) {
            return StepResult::skipped( __( 'There is no order to update.', 'yaymatik' ) );
        }
        $order->update_meta_data( $config['key'], self::render( $config['value'], $scope ) );
        $order->save();
        return StepResult::ok( [ 'key' => $config['key'] ] );
    }
}
