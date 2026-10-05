<?php
namespace YayAutomation\Engine\Blocks\Conditions;

use YayAutomation\Engine\Blocks\Core\Condition;

defined( 'ABSPATH' ) || exit;

/** Config: [ 'operator' => 'gt'|'gte'|'lt'|'lte', 'amount' => float|null ]. Compares the order total incl. tax and shipping. */
class OrderTotal extends Condition {

    public const ID = 'order_total';

    private const OPERATORS = [ 'gt', 'gte', 'lt', 'lte' ];

    public function id(): string {
        return self::ID;
    }

    public function sanitize( $config ): array {
        $config = is_array( $config ) ? $config : [];
        $amount = $config['amount'] ?? null;

        return [
            'operator' => sanitize_key( $config['operator'] ?? '' ),
            'amount'   => is_numeric( $amount ) ? (float) $amount : null,
        ];
    }

    public function validate( array $config ) {
        if ( ! in_array( $config['operator'], self::OPERATORS, true ) ) {
            return $this->invalid( __( 'Choose a comparison.', 'yay-automation' ) );
        }
        if ( null === $config['amount'] || $config['amount'] < 0 ) {
            return $this->invalid( __( 'Enter an amount of 0 or more.', 'yay-automation' ) );
        }
        return true;
    }

    public function evaluate( array $context, array $config ): bool {
        $total  = (float) $context['order']->get_total();
        $amount = (float) $config['amount'];

        switch ( $config['operator'] ) {
            case 'gt':
                return $total > $amount;
            case 'gte':
                return $total >= $amount;
            case 'lt':
                return $total < $amount;
            case 'lte':
                return $total <= $amount;
        }
        return false;
    }
}
