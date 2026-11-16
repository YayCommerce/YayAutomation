<?php
namespace YayMatik\Workflow\Actions;

use YayMatik\Workflow\DataItems\OrderData;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Steps\Action;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\StepResult;
use YayMatik\Workflow\Triggers\OrderNoteAdded;

defined( 'ABSPATH' ) || exit;

/** Adds a note to the order; WooCommerce emails a customer note to the customer. */
class AddOrderNote extends Action {

    public const ID = 'add_order_note';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Add order note', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Add a private note to the order, or a note the customer gets by email.', 'yaymatik' );
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

    public function emits(): array {
        return [ OrderNoteAdded::ID ];
    }

    public function fields(): array {
        return [
            Field::select( 'type', __( 'Note type', 'yaymatik' ), self::types() )->default( 'private' ),
            Field::textarea( 'note', __( 'Note', 'yaymatik' ) )
                /* translators: {{ step }}: step name, e.g. "Add order note"; keep {{ … }} as it is. */
                ->required( __( '{{ step }} needs a note.', 'yaymatik' ) )
                ->variables(),
        ];
    }

    public function summary(): string {
        /* translators: {{ config.type }}: note type (private or to the customer), {{ config.note }}: the note text; keep {{ … }} as it is. */
        return __( '{{ config.type }}: {{ config.note }}', 'yaymatik' );
    }

    public function outputs(): array {
        return [
            'type' => [
                'label'   => __( 'Note type', 'yaymatik' ),
                'type'    => Type::enum(),
                'options' => self::types(),
            ],
        ];
    }

    public function run( Scope $scope, array $config ): StepResult {
        $order = $scope->entity( OrderData::ID );
        if ( ! $order ) {
            return StepResult::skipped( __( 'There is no order to add the note to.', 'yaymatik' ) );
        }
        // WooCommerce shows notes through wp_kses_post() but emails a customer note as is.
        $note = wp_kses_post( self::render( $config['note'], $scope ) );
        $order->add_order_note( $note, 'customer' === $config['type'] ? 1 : 0 );
        return StepResult::ok( [ 'type' => $config['type'] ] );
    }

    private static function types(): array {
        return [
            'private'  => __( 'Private note', 'yaymatik' ),
            'customer' => __( 'Note to customer', 'yaymatik' ),
        ];
    }
}
