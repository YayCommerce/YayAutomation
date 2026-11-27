<?php
namespace YayMatik\Pro\Workflow\Actions;

use YayMatik\Workflow\DataItems\OrderData;
use YayMatik\Workflow\Core\Data\MetaKeys;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Steps\Action;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\StepResult;
use YayMatik\Workflow\Triggers\OrderNoteAdded;

defined( 'ABSPATH' ) || exit;

/** Sets an order meta value; an empty value is stored as is. */
class UpdateOrderMeta extends Action {

    public const ID = 'update_order_meta';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Update order custom field', 'yaymatik-automation-for-woocommerce' );
    }

    public function description(): string {
        return __( 'Set a custom field (meta) on the order.', 'yaymatik-automation-for-woocommerce' );
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

    public function emits(): array {
        return [ OrderNoteAdded::ID ];
    }

    public function fields(): array {
        return [
            Field::text( 'key', __( 'Field name', 'yaymatik-automation-for-woocommerce' ) )
                /* translators: {{ step }}: step name; keep {{ … }} as it is. */
                ->required( __( '{{ step }} needs a field name.', 'yaymatik-automation-for-woocommerce' ) )
                ->help( __( 'The meta key, e.g. _vip_order.', 'yaymatik-automation-for-woocommerce' ) ),
            Field::text( 'value', __( 'Value', 'yaymatik-automation-for-woocommerce' ) )->variables(),
        ];
    }

    public function summary(): string {
        /* translators: {{ config.key }}: custom field name, {{ config.value }}: its value; keep {{ … }} as it is. */
        return __( 'Set {{ config.key }} to {{ config.value }}', 'yaymatik-automation-for-woocommerce' );
    }

    public function outputs(): array {
        return [
            'key'     => [
                'label' => __( 'Field name', 'yaymatik-automation-for-woocommerce' ),
                'type'  => Type::string(),
            ],
            'changed' => [
                'label' => __( 'Value changed', 'yaymatik-automation-for-woocommerce' ),
                'type'  => Type::boolean(),
            ],
        ];
    }

    /**
     * Refuses the fields WooCommerce and YayMatik keep for themselves.
     *
     * @param array $config
     */
    public function validate( array $config ) {
        $error = $this->field_error( $config );
        if ( $error ) {
            return $error;
        }
        return MetaKeys::order_blocked( new \WC_Order(), $config['key'] ) ? $this->invalid( self::blocked_message(), 'key' ) : true;
    }

    public function run( Scope $scope, array $config ): StepResult {
        $order = $scope->entity( OrderData::ID );
        if ( ! $order ) {
            return StepResult::skipped( __( 'There is no order to update.', 'yaymatik-automation-for-woocommerce' ) );
        }
        $key = $config['key'];
        // `validate()` only saw a blank order; this order's data store has the last word.
        if ( MetaKeys::order_blocked( $order, $key ) ) {
            return StepResult::failed( self::blocked_message() );
        }
        $stored = $order->get_meta( $key );
        $old    = is_scalar( $stored ) ? (string) $stored : '';
        $new    = self::render( $config['value'], $scope );
        // A saved order runs every save hook, so the same value isn't written again.
        if ( $order->meta_exists( $key ) && is_scalar( $stored ) && $old === $new ) {
            return StepResult::ok(
                [
                    'key'     => $key,
                    'changed' => false,
                ]
            );
        }
        $order->update_meta_data( $key, $new );
        $order->save();
        $order->add_order_note(
            /* translators: 1: custom field name, 2: its previous value, 3: its new value */
            sprintf( __( 'Custom field %1$s changed from “%2$s” to “%3$s” by a YayMatik workflow.', 'yaymatik-automation-for-woocommerce' ), $key, $old, $new )
        );
        // Runs keep refs only: the rendered values stay in the note, with the order.
        return StepResult::ok(
            [
                'key'     => $key,
                'changed' => true,
            ]
        );
    }

    private static function blocked_message(): string {
        return __( 'WooCommerce or YayMatik manages this field. Choose another field name.', 'yaymatik-automation-for-woocommerce' );
    }
}
