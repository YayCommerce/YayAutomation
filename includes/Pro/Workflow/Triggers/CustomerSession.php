<?php
namespace YayMatik\Pro\Workflow\Triggers;

use YayMatik\Pro\Workflow\Core\Session\SessionSignals;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\RefResolver;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\Trigger;
use YayMatik\Workflow\DataItems\CustomerData;

defined( 'ABSPATH' ) || exit;

/**
 * Base for a signed-in person starting a visit: signing in, the automatic sign-in after sign-up, or coming back with a saved session.
 * Once per person every `days` days. Event args hold `user_id`, `how` (`sign_in`, `auto`, `returned`), `time`.
 */
abstract class CustomerSession extends Trigger {

    /** `how` of an event => the `when` value that takes it. */
    private const WHEN = [
        'sign_in'  => 'sign_in',
        'auto'     => 'auto',
        'returned' => 'return',
    ];

    public function category(): string {
        return 'customer';
    }

    public function icon(): string {
        return 'user';
    }

    public function provides(): array {
        return [ CustomerData::ID ];
    }

    public function claims_per_workflow(): bool {
        return true;
    }

    /**
     * @param array $config
     */
    public function claim_cooldown( array $config ): int {
        return max( 1, (int) ( $config['days'] ?? 0 ) ) * DAY_IN_SECONDS;
    }

    public function fields(): array {
        return [
            Field::multiselect( 'when', __( 'When to trigger', 'yaymatik-automation-for-woocommerce' ), [ self::class, 'whens' ] )
                ->required()
                ->default( [ 'sign_in', 'return' ] ),
            Field::number( 'days', __( 'Once per person every N days', 'yaymatik-automation-for-woocommerce' ) )
                ->required()
                ->default( 7 )
                ->min( 1 )
                ->max( 365 ),
        ];
    }

    /** `when` value => its label. */
    public static function whens(): array {
        return [
            'sign_in' => __( 'Sign-in', 'yaymatik-automation-for-woocommerce' ),
            'return'  => __( 'Return with a saved session', 'yaymatik-automation-for-woocommerce' ),
            'auto'    => __( 'Automatic sign-in after sign-up or checkout', 'yaymatik-automation-for-woocommerce' ),
        ];
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

    public function facts(): array {
        return [
            'time' => [
                'label'        => __( 'Trigger time', 'yaymatik-automation-for-woocommerce' ),
                'type'         => DataItem::TYPE_DATE,
                'value_type'   => Type::date(),
                'options'      => null,
                'conditional'  => true,
                'merge_tag'    => true,
                'block'        => false,
                'display'      => null,
                'timestamp'    => null,
                'help'         => null,
                'search'       => null,
                'meta'         => null,
                'meta_blocked' => null,
            ],
        ];
    }

    public function sample_facts(): array {
        return [ 'time' => time() ];
    }

    public function listen( callable $emit ): void {
        SessionSignals::instance()->subscribe( $emit );
    }

    /**
     * @param array $args
     */
    public function subject( array $args ): string {
        return 'user:' . (int) ( $args['user_id'] ?? 0 );
    }

    /**
     * @param array $args
     */
    public function context( array $args ): ?array {
        $user     = get_userdata( (int) ( $args['user_id'] ?? 0 ) );
        $customer = $user ? RefResolver::account( (int) $user->ID ) : null;
        if ( ! $customer ) {
            return null;
        }
        return [
            CustomerData::ID => $customer,
            'user'           => $user,
            'how'            => (string) ( $args['how'] ?? '' ),
        ] + $this->about( $user );
    }

    /**
     * What `person_matches()` reads about the person: once per event, not once per workflow.
     *
     * @param \WP_User $user
     */
    protected function about( $user ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- for a trigger that needs more than the user.
        return [];
    }

    /**
     * The customer and the confirmation time, kept for every later step.
     *
     * @param array $args
     */
    public function refs( array $args ): ?array {
        if ( ! get_userdata( (int) ( $args['user_id'] ?? 0 ) ) ) {
            return null;
        }
        return [
            CustomerData::ID => new Ref( CustomerData::ID, $this->subject( $args ) ),
            'time'           => (int) ( $args['time'] ?? 0 ),
        ];
    }

    /**
     * @param array $context
     * @param array $config
     */
    public function matches( array $context, array $config ): bool {
        $when = self::WHEN[ $context['how'] ] ?? null;
        return null !== $when && in_array( $when, (array) ( $config['when'] ?? [] ), true ) && $this->person_matches( $context, $config );
    }

    /**
     * Whether this person counts for a workflow with this config.
     *
     * @param array $context
     * @param array $config
     */
    abstract protected function person_matches( array $context, array $config ): bool;
}
