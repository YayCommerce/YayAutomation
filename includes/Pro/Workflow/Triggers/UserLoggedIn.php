<?php
namespace YayMatik\Pro\Workflow\Triggers;

use YayMatik\Pro\Workflow\DataItems\CustomerProData;
use YayMatik\Workflow\Core\Steps\Field;

defined( 'ABSPATH' ) || exit;

/** Anyone with one of the chosen roles signs in or comes back. */
class UserLoggedIn extends CustomerSession {

    public const ID = 'user_logged_in';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'User logged in', 'yaymatik-automation-for-woocommerce' );
    }

    public function description(): string {
        return __( 'A user with one of the chosen roles logs in or comes back', 'yaymatik-automation-for-woocommerce' );
    }

    public function summary(): string {
        /* translators: {{ config.days }}: number of days; keep {{ … }} as it is. */
        return __( 'When a user logs in · once every {{ config.days }} days', 'yaymatik-automation-for-woocommerce' );
    }

    public function fields(): array {
        return array_merge(
            parent::fields(),
            [
                Field::multiselect( 'roles', __( 'Roles', 'yaymatik-automation-for-woocommerce' ), [ CustomerProData::class, 'roles' ] )
                    ->default( [ 'customer' ] )
                    ->placeholder( __( 'All roles', 'yaymatik-automation-for-woocommerce' ) )
                    ->help( __( 'Leave empty for all roles.', 'yaymatik-automation-for-woocommerce' ) ),
            ]
        );
    }

    /**
     * @param array $context
     * @param array $config
     */
    protected function person_matches( array $context, array $config ): bool {
        $roles = (array) ( $config['roles'] ?? [] );
        return ! $roles || (bool) array_intersect( $roles, (array) $context['user']->roles );
    }
}
