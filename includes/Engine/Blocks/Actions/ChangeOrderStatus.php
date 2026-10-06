<?php
namespace YayAutomation\Engine\Blocks\Actions;

use YayAutomation\Engine\Blocks\Core\Action;
use YayAutomation\Engine\Data\OrderData;
use YayAutomation\Engine\Plugins;

defined( 'ABSPATH' ) || exit;

/** Config: [ 'status' => slug without the `wc-` prefix ]. */
class ChangeOrderStatus extends Action {

    public const ID = 'change_order_status';

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

        return [ 'status' => OrderData::status_slug( (string) ( $config['status'] ?? '' ) ) ];
    }

    public function validate( array $config ) {
        $known = array_keys( OrderData::statuses() );
        if ( '' === $config['status'] || ( $known && ! in_array( $config['status'], $known, true ) ) ) {
            return $this->invalid( __( 'Choose a status.', 'yay-automation' ) );
        }
        return true;
    }

    public function run( array $context, array $config ) {
        $order = $context[ OrderData::ID ];
        if ( $order->get_status() !== $config['status'] ) {
            $order->update_status( $config['status'], __( 'Status changed by a YayAutomation workflow.', 'yay-automation' ) );
        }
        return true;
    }
}
