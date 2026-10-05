<?php
namespace YayAutomation\Engine\Blocks\Actions;

use YayAutomation\Engine\Blocks\Core\Action;
use YayAutomation\Engine\Data\OrderData;
use YayAutomation\Engine\Data\Variables;
use YayAutomation\Engine\Plugins;

defined( 'ABSPATH' ) || exit;

/**
 * Config: [ 'note' => string with merge tags, 'type' => 'private'|'customer' ].
 * A customer note is also emailed to the customer by WooCommerce.
 */
class AddOrderNote extends Action {

    public const ID = 'add_order_note';

    private const TYPES = [ 'private', 'customer' ];

    public function id(): string {
        return self::ID;
    }

    public function plugin(): string {
        return Plugins::WOOCOMMERCE;
    }

    public function category(): string {
        return self::CATEGORY_ORDER;
    }

    public function requires(): array {
        return [ OrderData::ID ];
    }

    public function sanitize( $config ): array {
        $config = is_array( $config ) ? $config : [];
        $type   = (string) ( $config['type'] ?? '' );

        return [
            'note' => self::plain_text( (string) ( $config['note'] ?? '' ) ),
            'type' => in_array( $type, self::TYPES, true ) ? $type : 'private',
        ];
    }

    public function validate( array $config ) {
        if ( '' === $config['note'] ) {
            return $this->invalid( __( 'Enter a note.', 'yay-automation' ) );
        }
        return $this->unknown_variable( $config['note'] ) ?? true;
    }

    public function run( array $context, array $config ) {
        // WooCommerce shows notes through wp_kses_post() but emails a customer note as is.
        $note = wp_kses_post( Variables::render( $config['note'], $context ) );
        $context[ OrderData::ID ]->add_order_note( $note, 'customer' === $config['type'] ? 1 : 0 );
        return true;
    }
}
