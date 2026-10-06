<?php
namespace YayAutomation\Engine\Blocks\Actions;

use YayAutomation\Engine\Blocks\Core\Action;
use YayAutomation\Engine\Data\OrderData;
use YayAutomation\Engine\Data\Variables;
use YayAutomation\Engine\Plugins;

defined( 'ABSPATH' ) || exit;

/** Config: [ 'key' => meta key, 'value' => string with merge tags ]. An empty value is stored as is. */
class UpdateOrderMeta extends Action {

    public const ID = 'update_order_meta';

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

        return [
            'key'   => sanitize_text_field( (string) ( $config['key'] ?? '' ) ),
            'value' => self::single_line( (string) ( $config['value'] ?? '' ) ),
        ];
    }

    public function validate( array $config ) {
        if ( '' === $config['key'] ) {
            return $this->required();
        }
        return $this->unknown_variable( $config['value'] ) ?? true;
    }

    public function run( array $context, array $config ) {
        $order = $context[ OrderData::ID ];
        $order->update_meta_data( $config['key'], Variables::render( $config['value'], $context ) );
        $order->save();
        return true;
    }
}
