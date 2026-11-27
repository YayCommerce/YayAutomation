<?php
namespace YayMatik\Pro\Workflow\Triggers;

use YayMatik\Workflow\Triggers\OrderTrigger;
use YayMatik\Workflow\Core\Steps\Field;

defined( 'ABSPATH' ) || exit;

/** A person's last real order is a number of days old, found by `InactiveSweep`. Event args hold `order_id`, `days`. */
class CustomerInactive extends OrderTrigger {

    public const ID = 'customer_inactive';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Customer inactive', 'yaymatik-automation-for-woocommerce' );
    }

    public function description(): string {
        return __( 'A customer has not ordered for a number of days', 'yaymatik-automation-for-woocommerce' );
    }

    public function icon(): string {
        return 'hourglass';
    }

    public function category(): string {
        return 'customer';
    }

    public function claims_per_workflow(): bool {
        return true;
    }

    public function fields(): array {
        return [
            Field::number( 'days', __( 'Days without an order', 'yaymatik-automation-for-woocommerce' ) )
                ->required()
                ->default( 90 )
                ->min( 7 )
                ->max( 730 ),
        ];
    }

    public function summary(): string {
        /* translators: {{ config.days }}: number of days; keep {{ … }} as it is. */
        return __( 'When {{ config.days }} days without an order', 'yaymatik-automation-for-woocommerce' );
    }

    /**
     * Whole days: the field rules only check the range.
     *
     * @param mixed $config
     */
    public function sanitize( $config ): array {
        $config = Field::sanitize_all( $this->fields(), $config );
        if ( is_numeric( $config['days'] ) ) {
            $config['days'] = (int) round( $config['days'] );
        }
        return $config;
    }

    public function listen( callable $emit ): void {
        add_action(
            'yaymatik_customer_inactive',
            function ( $order_id, $days ) use ( $emit ) {
                $emit(
                    [
                        'order_id' => (int) $order_id,
                        'days'     => (int) $days,
                    ]
                );
            },
            10,
            2
        );
    }

    public function context( array $args ): ?array {
        $context = parent::context( $args );
        return $context ? $context + [ 'days' => (int) ( $args['days'] ?? 0 ) ] : null;
    }

    public function matches( array $context, array $config ): bool {
        return (int) $context['days'] === (int) $config['days'];
    }
}
