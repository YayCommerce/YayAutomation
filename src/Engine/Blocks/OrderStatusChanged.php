<?php
namespace YayAutomation\Engine\Blocks;

defined( 'ABSPATH' ) || exit;

/** Config: [ 'from' => 'any'|status, 'to' => status ]. Statuses are slugs without the `wc-` prefix. */
class OrderStatusChanged extends Trigger {

    public const ID = 'order_status_changed';

    public function id(): string {
        return self::ID;
    }

    /**
     * Registered WooCommerce order statuses as [ [ 'value' => 'processing', 'label' => 'Processing' ], … ].
     * Empty when WooCommerce isn't loaded.
     */
    public static function statuses(): array {
        if ( ! function_exists( 'wc_get_order_statuses' ) ) {
            return [];
        }
        $statuses = [];
        foreach ( wc_get_order_statuses() as $slug => $label ) {
            $statuses[] = [
                'value' => self::strip_prefix( $slug ),
                'label' => $label,
            ];
        }
        return $statuses;
    }

    public function sanitize( $config ): array {
        $config = is_array( $config ) ? $config : [];
        $from   = self::strip_prefix( sanitize_key( $config['from'] ?? '' ) );

        return [
            'from' => '' === $from ? 'any' : $from,
            'to'   => self::strip_prefix( sanitize_key( $config['to'] ?? '' ) ),
        ];
    }

    public function validate( array $config ) {
        if ( '' === $config['to'] ) {
            return $this->invalid( __( 'Choose the status the order changes to.', 'yay-automation' ) );
        }

        $known = wp_list_pluck( self::statuses(), 'value' );
        // Without WooCommerce there's nothing to check against; the trigger just never fires.
        if ( $known ) {
            if ( ! in_array( $config['to'], $known, true ) ) {
                return $this->invalid( __( 'Unknown order status.', 'yay-automation' ) );
            }
            if ( 'any' !== $config['from'] && ! in_array( $config['from'], $known, true ) ) {
                return $this->invalid( __( 'Unknown order status.', 'yay-automation' ) );
            }
        }
        return true;
    }

    public function matches( array $context, array $config ): bool {
        return ( 'any' === $config['from'] || $config['from'] === $context['from'] ) && $config['to'] === $context['to'];
    }

    private static function strip_prefix( string $status ): string {
        return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
    }
}
