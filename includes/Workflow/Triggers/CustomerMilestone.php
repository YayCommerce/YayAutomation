<?php
namespace YayMatik\Workflow\Triggers;

use YayMatik\Workflow\Core\Data\CustomerStats;
use YayMatik\Workflow\Core\Storage\TriggerClaimStore;
use YayMatik\Workflow\DataItems\CustomerData;
use YayMatik\Workflow\DataItems\OrderData;

defined( 'ABSPATH' ) || exit;

/** Base for a customer's paid orders crossing a number: starts on the paid order that crosses it, once per person. */
abstract class CustomerMilestone extends OrderPaidTrigger {

    /** @var callable fn( int $user_id, string $email ): array, see `CustomerStats::totals()`. */
    private $totals;

    /**
     * @param callable|null $totals Null = `CustomerStats::totals()`.
     */
    public function __construct( ?callable $totals = null ) {
        $this->totals = $totals ?? [ CustomerStats::class, 'totals' ];
    }

    public function category(): string {
        return 'customer';
    }

    public function claims_per_workflow(): bool {
        return true;
    }

    /**
     * The person: `user:<id>`, else a hash of the billing email; '' for neither.
     *
     * @param array $args
     */
    public function claim_key( array $args ): string {
        $order = self::order( $args['order_id'] ?? 0 );
        return $order ? self::person( $order ) : '';
    }

    /**
     * Adds the person's paid totals after this order, and this order's share; null without a person.
     *
     * @param array $args
     */
    public function context( array $args ): ?array {
        $context = parent::context( $args );
        if ( ! $context || '' === self::person( $context[ OrderData::ID ] ) ) {
            return null;
        }
        $order  = $context[ OrderData::ID ];
        $totals = ( $this->totals )( CustomerData::user_id( $order ), (string) $order->get_billing_email() );
        return $context + [
            'after_count' => (int) $totals['count'],
            'after_spent' => (float) $totals['spent'],
            'this_count'  => 1,
            'this_spent'  => round( (float) $order->get_total() - (float) $order->get_total_refunded(), 8 ),
        ];
    }

    /**
     * Adds the claim key, so the Worker releases the right claim; a guest's is a hash, never the email.
     *
     * @param array $args
     */
    public function refs( array $args ): ?array {
        $refs = parent::refs( $args );
        $key  = $this->claim_key( $args );
        return $refs && '' !== $key ? $refs + [ 'claim_key' => $key ] : null;
    }

    public function matches( array $context, array $config ): bool {
        list( $before, $after ) = $this->progress( $context );
        $target                 = $this->target( $config );
        return $before < $target && $target <= $after;
    }

    /**
     * What the milestone counts, before this order and after it.
     *
     * @param array $context
     * @return float[] [ before, after ]
     */
    abstract protected function progress( array $context ): array;

    /**
     * The number to reach.
     *
     * @param array $config
     */
    abstract protected function target( array $config ): float;

    private static function person( \WC_Order $order ): string {
        $user_id = CustomerData::user_id( $order );
        if ( $user_id > 0 ) {
            return 'user:' . $user_id;
        }
        $email = (string) $order->get_billing_email();
        return '' === $email ? '' : TriggerClaimStore::email_key( $email );
    }
}
