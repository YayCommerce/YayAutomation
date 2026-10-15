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

/**
 * Config: [ 'note' => string with merge tags, 'type' => 'private'|'customer' ].
 * A customer note is also emailed to the customer by WooCommerce.
 */
class AddOrderNote extends Action {

    public const ID = 'add_order_note';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Add order note', 'yay-automation' );
    }

    public function description(): string {
        return __( 'Add a private note to the order, or a note the customer gets by email.', 'yay-automation' );
    }

    public function plugin(): string {
        return Plugins::WOOCOMMERCE;
    }

    public function module(): string {
        return Modules::CORE;
    }

    public function icon(): string {
        return 'message-square';
    }

    public function category(): string {
        return self::CATEGORY_ORDER;
    }

    public function requires(): array {
        return [ OrderData::ID ];
    }

    public function fields(): array {
        return [
            Field::select( 'type', __( 'Note type', 'yay-automation' ), self::types() )->default( 'private' ),
            Field::textarea( 'note', __( 'Note', 'yay-automation' ) )
                /* translators: %s: step name, e.g. "Add order note" */
                ->required( __( '%s needs a note.', 'yay-automation' ) )
                ->variables(),
        ];
    }

    public function summary(): string {
        /* translators: %type%: note type (private or to the customer), %note%: the note text; keep the placeholders as they are. */
        return __( '%type%: %note%', 'yay-automation' );
    }

    public function outputs(): array {
        return [
            'type' => [
                'label'   => __( 'Note type', 'yay-automation' ),
                'type'    => Type::enum(),
                'options' => self::types(),
            ],
        ];
    }

    /**
     * Stored keys keep their v0.1 order (note, type), whatever the form order.
     *
     * @param mixed $config Raw config from the request.
     */
    public function sanitize( $config ): array {
        $config = Field::sanitize_all( $this->fields(), $config );
        return [
            'note' => $config['note'],
            'type' => $config['type'],
        ];
    }

    public function run( Scope $scope, array $config ): StepResult {
        $order = $scope->entity( OrderData::ID );
        if ( ! $order ) {
            return StepResult::skipped( __( 'There is no order to add the note to.', 'yay-automation' ) );
        }
        // WooCommerce shows notes through wp_kses_post() but emails a customer note as is.
        $note = wp_kses_post( self::render( $config['note'], $scope ) );
        $order->add_order_note( $note, 'customer' === $config['type'] ? 1 : 0 );
        return StepResult::ok( [ 'type' => $config['type'] ] );
    }

    private static function types(): array {
        return [
            'private'  => __( 'Private note', 'yay-automation' ),
            'customer' => __( 'Note to customer', 'yay-automation' ),
        ];
    }
}
