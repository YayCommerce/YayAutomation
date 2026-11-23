<?php
namespace YayMatik\Pro\Workflow\Core;

defined( 'ABSPATH' ) || exit;

/** Who counts as store staff: never changed by Change customer role, never a customer to Customer logged in. */
final class StaffRule {

    /** Capabilities beyond a customer's: a role with one is never assigned by a workflow, a user with one is staff. Triggers fire on public events, so any buyer could get the role. */
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

    public const STAFF_ROLES = [ 'administrator', 'shop_manager' ];

    /**
     * A super admin, store staff, or anyone WordPress grants a protected capability: by role, directly or through a `user_has_cap` filter.
     *
     * @param \WP_User $user
     */
    public static function is_staff( $user ): bool {
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
}
