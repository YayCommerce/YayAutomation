<?php
namespace YayMatik\Workflow\Triggers;

use YayMatik\Workflow\Core\Runtime\Runtime;
use YayMatik\Workflow\Core\Steps\Field;

defined( 'ABSPATH' ) || exit;

/** Fires when a note is added to an order: by the store, WooCommerce (status changes) or a workflow. */
class OrderNoteAdded extends OrderTrigger {

    public const ID = 'order_note_added';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Order note added', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Starts when a note is added to an order.', 'yaymatik' );
    }

    public function icon(): string {
        return 'message-square';
    }

    public function fields(): array {
        return [
            Field::select( 'note_type', __( 'Note type', 'yaymatik' ), self::types() )->default( 'any' ),
            Field::text( 'contains', __( 'Note contains', 'yaymatik' ) )
                ->placeholder( __( 'Any text', 'yaymatik' ) )
                ->help( __( 'Not case sensitive. Leave empty for any note.', 'yaymatik' ) ),
            Field::toggle( 'skip_yaymatik', __( 'Skip notes added by YayMatik workflows', 'yaymatik' ) )
                ->default( true )
                ->help( __( 'Keeps a workflow from starting itself when a step adds a note or changes the order status.', 'yaymatik' ) ),
        ];
    }

    public function summary(): string {
        /* translators: %note_type%: which notes count, e.g. "Private note" or "Any note"; keep the placeholder as it is. */
        return __( '%note_type% added to an order', 'yaymatik' );
    }

    /**
     * Each note, and whether a run was being run when it was added.
     *
     * @param callable $emit
     */
    public function listen( callable $emit ): void {
        // A note WooCommerce adds for a status change carries no mark of who caused it.
        add_action(
            'woocommerce_order_note_added',
            function ( $note_id, $order = null ) use ( $emit ) {
                if ( ! $order instanceof \WC_Order ) {
                    return;
                }
                $worker = Runtime::worker();
                $emit(
                    [
                        'order_id' => (int) $order->get_id(),
                        'note_id'  => (int) $note_id,
                        'by_run'   => $worker && null !== $worker->current(),
                    ]
                );
            },
            10,
            2
        );
    }

    /**
     * Adds the note's type and text, read with its tags stripped; null when the note is gone.
     *
     * @param array $args
     */
    public function context( array $args ): ?array {
        $context = parent::context( $args );
        $note    = get_comment( absint( $args['note_id'] ?? 0 ) );
        if ( ! $context || ! $note || 'order_note' !== $note->comment_type || (int) $note->comment_post_ID !== (int) $args['order_id'] ) {
            return null;
        }
        return $context + [
            'note_type' => get_comment_meta( (int) $note->comment_ID, 'is_customer_note', true ) ? 'customer' : 'private',
            'note'      => self::plain( (string) $note->comment_content ),
            'by_run'    => ! empty( $args['by_run'] ),
        ];
    }

    public function matches( array $context, array $config ): bool {
        if ( $context['by_run'] && $config['skip_yaymatik'] ) {
            return false;
        }
        if ( 'any' !== $config['note_type'] && $context['note_type'] !== $config['note_type'] ) {
            return false;
        }
        // Saving the setting turns a lone `<` into `&lt;`, and a note may hold either form.
        $contains = self::plain( $config['contains'] );
        return '' === $contains || false !== strpos( wc_strtolower( $context['note'] ), wc_strtolower( $contains ) );
    }

    private static function plain( string $text ): string {
        return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
    }

    public function fires_for_own_runs( array $config ): bool {
        return ! ( $config['skip_yaymatik'] ?? true );
    }

    private static function types(): array {
        return [
            'any'      => __( 'Any note', 'yaymatik' ),
            'private'  => __( 'Private note', 'yaymatik' ),
            'customer' => __( 'Note to customer', 'yaymatik' ),
        ];
    }
}
