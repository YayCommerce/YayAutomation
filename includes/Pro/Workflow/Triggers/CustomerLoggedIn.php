<?php
namespace YayMatik\Pro\Workflow\Triggers;

use YayMatik\Pro\Workflow\Core\StaffRule;
use YayMatik\Workflow\Core\Data\CustomerStats;

defined( 'ABSPATH' ) || exit;

/** A customer with at least one paid order, never store staff, signs in or comes back. */
class CustomerLoggedIn extends CustomerSession {

    public const ID = 'customer_logged_in';

    /** @var callable fn( int $user_id, string $email ): array, see `CustomerStats::totals()`. */
    private $totals;

    /** @var callable fn( \WP_User $user ): bool */
    private $is_staff;

    /**
     * @param callable|null $totals   Null = `CustomerStats::totals()`.
     * @param callable|null $is_staff Null = `StaffRule::is_staff()`.
     */
    public function __construct( ?callable $totals = null, ?callable $is_staff = null ) {
        $this->totals   = $totals ?? [ CustomerStats::class, 'totals' ];
        $this->is_staff = $is_staff ?? [ StaffRule::class, 'is_staff' ];
    }

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Customer logged in', 'yaymatik-automation-for-woocommerce' );
    }

    public function description(): string {
        return __( 'A customer with a paid order logs in or comes back', 'yaymatik-automation-for-woocommerce' );
    }

    public function summary(): string {
        /* translators: {{ config.days }}: number of days; keep {{ … }} as it is. */
        return __( 'When a customer logs in · once every {{ config.days }} days', 'yaymatik-automation-for-woocommerce' );
    }

    /**
     * Whether the person is a customer. A failed order query throws, so no run starts.
     *
     * @param \WP_User $user
     */
    protected function about( $user ): array {
        return [ 'is_customer' => ! ( $this->is_staff )( $user ) && (int) ( $this->totals )( (int) $user->ID, (string) $user->user_email )['count'] > 0 ];
    }

    /**
     * @param array $context
     * @param array $config
     */
    protected function person_matches( array $context, array $config ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the base's signature.
        return $context['is_customer'];
    }
}
