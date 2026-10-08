<?php
namespace YayAutomation\Workflow\Extend;

defined( 'ABSPATH' ) || exit;

/**
 * Plugins that triggers and actions are grouped under in the editor's pickers. Only active plugins
 * are offered. Icons live in the admin app, keyed by id.
 */
final class Plugins {

    public const WOOCOMMERCE = 'woocommerce';

    /** Shape must match `PluginMeta` in apps/admin-page/src/types/localize.d.ts. */
    public static function to_client(): array {
        return [
            [
                'id'          => self::WOOCOMMERCE,
                'label'       => __( 'WooCommerce', 'yay-automation' ),
                'description' => __( 'Orders, customers and store emails.', 'yay-automation' ),
                'active'      => class_exists( 'WooCommerce' ),
            ],
        ];
    }
}
