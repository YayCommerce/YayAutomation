<?php
namespace YayMatik\Pro\Workflow\Triggers;

use YayMatik\Workflow\Core\Steps\Field;

defined( 'ABSPATH' ) || exit;

/** A customer's paid order count reaches a number. */
class CustomerOrderCountReaches extends CustomerMilestone {

    public const ID = 'customer_order_count_reaches';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Customer order count reaches', 'yaymatik' );
    }

    public function description(): string {
        return __( 'A customer’s paid orders reach a number', 'yaymatik' );
    }

    public function icon(): string {
        return 'activity';
    }

    public function fields(): array {
        return [
            Field::number( 'count', __( 'Paid orders', 'yaymatik' ) )
                ->required()
                ->default( 5 )
                ->min( 1 )
                ->help( __( 'Starts once per customer, on the paid order that reaches this number. Guests count by billing email.', 'yaymatik' ) ),
        ];
    }

    public function summary(): string {
        /* translators: {{ config.count }}: number of paid orders; keep {{ … }} as it is. */
        return __( 'When a customer reaches {{ config.count }} paid orders', 'yaymatik' );
    }

    /**
     * A whole number of orders.
     *
     * @param mixed $config
     */
    public function sanitize( $config ): array {
        $config = Field::sanitize_all( $this->fields(), $config );
        if ( is_numeric( $config['count'] ) ) {
            $config['count'] = (int) round( $config['count'] );
        }
        return $config;
    }

    protected function progress( array $context ): array {
        return [ (float) ( $context['after_count'] - $context['this_count'] ), (float) $context['after_count'] ];
    }

    protected function target( array $config ): float {
        return (float) $config['count'];
    }
}
