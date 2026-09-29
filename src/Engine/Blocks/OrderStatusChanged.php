<?php
namespace YayAutomation\Engine\Blocks;

defined( 'ABSPATH' ) || exit;

/** Config: [ 'from' => status[] (empty = any), 'to' => status[] ]. Statuses are slugs without the `wc-` prefix. */
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

        return [
            'from' => self::status_list( $config['from'] ?? [] ),
            'to'   => self::status_list( $config['to'] ?? [] ),
        ];
    }

    public function validate( array $config ) {
        if ( ! $config['to'] ) {
            return $this->invalid( __( 'Choose at least one status the order changes to.', 'yay-automation' ) );
        }

        $known = wp_list_pluck( self::statuses(), 'value' );
        // Without WooCommerce there's nothing to check against; the trigger just never fires.
        if ( $known && array_diff( array_merge( $config['from'], $config['to'] ), $known ) ) {
            return $this->invalid( __( 'Unknown order status.', 'yay-automation' ) );
        }
        return true;
    }

    /**
     * Empty `from` means any previous status.
     *
     * @param array $context Run context, see Block.
     * @param array $config  Sanitized, valid config.
     */
    public function matches( array $context, array $config ): bool {
        return ( ! $config['from'] || in_array( $context['from'], $config['from'], true ) )
            && in_array( $context['to'], $config['to'], true );
    }

    /**
     * Status list from raw input. Also reads the old single-string format ('any' or one status),
     * so workflows saved before statuses became lists keep working.
     *
     * @param mixed $raw Array of statuses, or a legacy string.
     */
    private static function status_list( $raw ): array {
        if ( is_string( $raw ) ) {
            $raw = 'any' === $raw ? [] : [ $raw ];
        }
        $list = [];
        foreach ( is_array( $raw ) ? $raw : [] as $status ) {
            $status = is_string( $status ) ? self::strip_prefix( sanitize_key( $status ) ) : '';
            if ( '' !== $status ) {
                $list[] = $status;
            }
        }
        return array_values( array_unique( $list ) );
    }

    private static function strip_prefix( string $status ): string {
        return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
    }
}
