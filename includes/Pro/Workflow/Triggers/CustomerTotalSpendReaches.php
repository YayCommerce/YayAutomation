<?php
namespace YayMatik\Pro\Workflow\Triggers;

use YayMatik\Workflow\Core\Steps\Field;

defined( 'ABSPATH' ) || exit;

/** A customer's total spent (paid orders minus refunds) reaches an amount. */
class CustomerTotalSpendReaches extends CustomerMilestone {

    public const ID = 'customer_total_spend_reaches';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Customer total spend reaches', 'yaymatik-automation-for-woocommerce' );
    }

    public function description(): string {
        return __( 'A customer’s total spent reaches an amount', 'yaymatik-automation-for-woocommerce' );
    }

    public function icon(): string {
        return 'heart-pulse';
    }

    public function fields(): array {
        return [
            Field::number( 'amount', __( 'Total spent', 'yaymatik-automation-for-woocommerce' ) )
                ->required()
                ->min( 0.01 )
                ->help( __( 'Paid orders minus refunds. Orders in other currencies are added as they are, without converting.', 'yaymatik-automation-for-woocommerce' ) ),
        ];
    }

    public function summary(): string {
        /* translators: {{ config.amount }}: an amount of money; keep {{ … }} as it is. */
        return __( 'When a customer’s total spent reaches {{ config.amount }}', 'yaymatik-automation-for-woocommerce' );
    }

    protected function progress( array $context ): array {
        return [ round( $context['after_spent'] - $context['this_spent'], 8 ), $context['after_spent'] ];
    }

    protected function target( array $config ): float {
        return (float) $config['amount'];
    }
}
