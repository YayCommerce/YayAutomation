<?php
namespace YayMatik\Pro\Workflow\Actions;

use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Steps\Action;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\StepResult;
use YayMatik\Workflow\DataItems\CustomerData;

defined( 'ABSPATH' ) || exit;

/** Adds, removes or replaces a role on the customer's account; never on store staff, never their last role. */
class ChangeCustomerRole extends Action {

    public const ID = 'change_customer_role';

    /** Capabilities beyond a customer's: a role with one is never assigned, a user with one never changed. Triggers fire on public events, so any buyer could get the role. */
    public const PROTECTED_CAPS = [
        'manage_options',
        'manage_woocommerce',
        'edit_users',
        'promote_users',
        'create_users',
        'delete_users',
        'remove_users',
        'list_users',
        'install_plugins',
        'activate_plugins',
        'edit_plugins',
        'delete_plugins',
        'update_plugins',
        'install_themes',
        'switch_themes',
        'edit_themes',
        'delete_themes',
        'update_themes',
        'edit_theme_options',
        'update_core',
        'edit_files',
        'unfiltered_html',
        'unfiltered_upload',
        'edit_shop_orders',
        'edit_posts',
        'publish_posts',
        'edit_others_posts',
        'edit_pages',
        'upload_files',
        'moderate_comments',
        'edit_products',
        'edit_shop_coupons',
        'manage_product_terms',
        'view_woocommerce_reports',
        'import',
        'export',
    ];

    private const STAFF_ROLES = [ 'administrator', 'shop_manager' ];

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Change customer role', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Add, remove or replace a role on the customer’s account.', 'yaymatik' );
    }

    public function icon(): string {
        return 'user';
    }

    public function category(): string {
        return self::CATEGORY_CUSTOMER;
    }

    public function requires(): array {
        return [ CustomerData::ID ];
    }

    public function fields(): array {
        return [
            Field::select(
                'mode',
                __( 'Change', 'yaymatik' ),
                [
                    'add'     => __( 'Add a role', 'yaymatik' ),
                    'remove'  => __( 'Remove a role', 'yaymatik' ),
                    'replace' => __( 'Replace roles', 'yaymatik' ),
                ]
            )->default( 'add' ),
            Field::select( 'role', __( 'Role', 'yaymatik' ), [ self::class, 'assignable_roles' ] )
                /* translators: {{ step }}: step name, e.g. "Change customer role"; keep {{ … }} as it is. */
                ->required( __( '{{ step }} needs a role.', 'yaymatik' ) )
                ->help( __( 'Only roles with customer rights are listed: no staff, editing or publishing roles.', 'yaymatik' ) ),
        ];
    }

    public function summary(): string {
        /* translators: {{ config.mode }}: e.g. "Add a role", {{ config.role }}: a role name; keep {{ … }} as it is. */
        return __( '{{ config.mode }}: {{ config.role }}', 'yaymatik' );
    }

    public function outputs(): array {
        return [
            'from' => [
                'label' => __( 'Roles before', 'yaymatik' ),
                'type'  => Type::string(),
            ],
            'to'   => [
                'label' => __( 'Roles after', 'yaymatik' ),
                'type'  => Type::string(),
            ],
        ];
    }

    /** Role slug => name: every role but store staff and roles with a protected capability, unless the filter opens them all. */
    public static function assignable_roles(): array {
        $allow_all = self::allow_all();
        $names     = [];
        foreach ( wp_roles()->roles as $slug => $role ) {
            if ( $allow_all || ! self::role_protected( (string) $slug ) ) {
                $names[ (string) $slug ] = translate_user_role( $role['name'] );
            }
        }
        return $names;
    }

    public function run( Scope $scope, array $config ): StepResult {
        $customer = $scope->entity( CustomerData::ID );
        $user     = $customer ? get_userdata( CustomerData::user_id( $customer ) ) : false;
        if ( ! $user ) {
            return StepResult::skipped( __( 'This customer has no account.', 'yaymatik' ) );
        }
        $allow_all = self::allow_all();
        // Write actions run as user 0, so these checks can't lean on the current user.
        if ( ! $allow_all && self::user_protected( $user ) ) {
            return StepResult::skipped( __( 'This customer has a protected role.', 'yaymatik' ) );
        }
        $role   = (string) $config['role'];
        $before = array_values( $user->roles );
        $giving = 'remove' !== $config['mode'];
        // Removing a role that is gone or now protected is harmless: this user can't hold it unless the filter allows it.
        if ( $giving && ! isset( wp_roles()->roles[ $role ] ) ) {
            /* translators: %s: role slug */
            return StepResult::failed( sprintf( __( 'The role %s no longer exists.', 'yaymatik' ), $role ) );
        }
        if ( $giving && ! $allow_all && self::role_protected( $role ) ) {
            /* translators: %s: role name */
            return StepResult::failed( sprintf( __( '%s can now manage the site, so it can’t be given.', 'yaymatik' ), self::names( [ $role ] ) ) );
        }
        if ( ! $giving && [ $role ] === $before ) {
            /* translators: %s: role name */
            return StepResult::skipped( sprintf( __( '%s is their only role.', 'yaymatik' ), self::names( [ $role ] ) ) );
        }
        // Added first, so the account is never left without a role.
        if ( $giving && ! in_array( $role, $before, true ) ) {
            $user->add_role( $role );
        }
        $drop = $giving ? [] : array_intersect( [ $role ], $before );
        if ( 'replace' === $config['mode'] ) {
            // With the filter on, a protected role they hold stays.
            $drop = array_filter( $before, fn( string $old ) => $old !== $role && ( ! $allow_all || ! self::role_protected( $old ) ) );
        }
        foreach ( $drop as $old ) {
            $user->remove_role( $old );
        }

        return StepResult::ok(
            [
                'from' => self::names( $before ),
                'to'   => self::names( array_values( $user->roles ) ),
            ]
        );
    }

    private static function allow_all(): bool {
        // Lifts the protected-role checks, never the last-role one.
        return (bool) apply_filters( 'yaymatik_change_role_allow_all', false );
    }

    /**
     * Store staff, a role that doesn't exist, or one with a protected capability.
     *
     * @param string $slug
     */
    private static function role_protected( string $slug ): bool {
        $role = wp_roles()->roles[ $slug ] ?? null;
        return in_array( $slug, self::STAFF_ROLES, true )
            || ! $role
            || (bool) array_intersect( array_keys( array_filter( (array) $role['capabilities'] ) ), self::PROTECTED_CAPS );
    }

    /**
     * A super admin, store staff, or anyone WordPress grants a protected capability: by role, directly or through a `user_has_cap` filter.
     *
     * @param \WP_User $user
     */
    private static function user_protected( $user ): bool {
        if ( is_super_admin( $user->ID ) || array_intersect( (array) $user->roles, self::STAFF_ROLES ) ) {
            return true;
        }
        foreach ( self::PROTECTED_CAPS as $cap ) {
            if ( user_can( $user, $cap ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Role names joined by ", ": fixed labels, never a customer's data.
     *
     * @param string[] $slugs
     */
    private static function names( array $slugs ): string {
        $roles = wp_roles()->roles;
        return implode( ', ', array_map( fn( string $slug ) => isset( $roles[ $slug ] ) ? translate_user_role( $roles[ $slug ]['name'] ) : $slug, $slugs ) );
    }
}
