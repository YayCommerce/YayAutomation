<?php
namespace YayMatik\Workflow\Triggers;

use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Steps\Trigger;
use YayMatik\Workflow\DataItems\CustomerData;

defined( 'ABSPATH' ) || exit;

/** A shopper creates an account. An account saved through `WC_Customer` (REST, imports) or Users → Add New doesn't count. Event args hold `customer_id`. */
class CustomerAccountCreated extends Trigger {

    public const ID = 'customer_account_created';

    /** @var true[] User id => saved through `WC_Customer` in this request. */
    private $saved_customers = [];

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Customer account created', 'yaymatik' );
    }

    public function description(): string {
        return __( 'A customer creates an account', 'yaymatik' );
    }

    public function icon(): string {
        return 'user-plus';
    }

    public function summary(): string {
        return __( 'When a customer creates an account', 'yaymatik' );
    }

    public function category(): string {
        return 'customer';
    }

    public function provides(): array {
        return [ CustomerData::ID ];
    }

    public function fields(): array {
        return [];
    }

    public function subject( array $args ): string {
        return 'user:' . (int) ( $args['customer_id'] ?? 0 );
    }

    public function listen( callable $emit ): void {
        add_action(
            'woocommerce_created_customer',
            function ( $customer_id ) use ( $emit ) {
                $emit( [ 'customer_id' => (int) $customer_id ] );
            }
        );
        // `WC_Customer::save()` goes through wc_create_new_customer() too, so REST and imports fire the hook above: an ERP sync would email its whole list. Only that path fires this one, right after.
        add_action(
            'woocommerce_new_customer',
            function ( $customer_id ) {
                $this->saved_customers[ (int) $customer_id ] = true;
            }
        );
    }

    /**
     * Runs at the end of the request, after both hooks.
     *
     * @param array $args
     */
    public function claim( array $args ): bool {
        return ! isset( $this->saved_customers[ (int) ( $args['customer_id'] ?? 0 ) ] );
    }

    /**
     * @param array $args
     */
    public function context( array $args ): ?array {
        $customer = RefResolver::account( (int) ( $args['customer_id'] ?? 0 ) );
        return $customer ? [ CustomerData::ID => $customer ] : null;
    }

    /**
     * @param array $args
     */
    public function refs( array $args ): ?array {
        return get_userdata( (int) ( $args['customer_id'] ?? 0 ) ) ? [ CustomerData::ID => new Ref( CustomerData::ID, $this->subject( $args ) ) ] : null;
    }
}
