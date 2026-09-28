<?php

namespace YayAutomation\Engine\BEPages;

use YayAutomation\YayAutomation;
use YayAutomation\Utils\SingletonTrait;
use YayAutomation\Engine\Register\ScriptName;
use YayAutomation\Engine\Blocks\OrderStatusChanged;

defined( 'ABSPATH' ) || exit;

/** Admin Page — mounts the React app */
class AdminPage {

    use SingletonTrait;

    public const MENU_SLUG = 'yay-automation';

    public const CAPABILITY = 'manage_options';

    public $hook_suffix = null;

    protected function __construct() {
        add_action( 'admin_menu', [ $this, 'admin_menu' ], 10 );
        add_filter( 'admin_body_class', [ $this, 'admin_body_class' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );
        add_filter( 'plugin_action_links_' . YAY_AUTOMATION_BASE_NAME, [ $this, 'add_action_links' ] );
    }

    public function add_action_links( $links ) {
        $links = array_merge(
            [
                '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">' . __( 'Workflows', 'yay-automation' ) . '</a>',
            ],
            $links
        );
        return $links;
    }

    public function admin_menu() {
        $this->hook_suffix = add_menu_page(
            __( 'YayAutomation', 'yay-automation' ),
            __( 'YayAutomation', 'yay-automation' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            [ $this, 'render_page' ],
            'dashicons-randomize',
            30
        );
        add_action( 'load-' . $this->hook_suffix, [ $this, 'on_load_page' ] );
    }

    public function admin_body_class( $classes ) {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== $this->hook_suffix ) {
            return $classes;
        }
        if ( strpos( $classes, 'yay-ui' ) === false ) {
            $classes .= ' yay-ui';
        }
        return $classes;
    }

    public function admin_enqueue_scripts( $hook_suffix ) {
        if ( $hook_suffix !== $this->hook_suffix ) {
            return;
        }

        // Shape must match apps/admin-page/src/types/localize.d.ts.
        $meta = [
            'plugin' => [
                'pluginUrl' => YAY_AUTOMATION_PLUGIN_URL,
                'version'   => YAY_AUTOMATION_VERSION,
            ],
            'wp'     => [
                'restRoot'       => esc_url_raw( rest_url() ),
                'restBase'       => 'yay-automation/v1',
                'restNonce'      => wp_create_nonce( 'wp_rest' ),
                'siteUrl'        => site_url(),
                'adminUrl'       => admin_url(),
                'timezoneString' => get_option( 'timezone_string' ),
                'gmtOffset'      => (float) get_option( 'gmt_offset', 0 ),
                'adminEmail'     => get_option( 'admin_email' ),
            ],
            'wc'     => [
                'active'         => class_exists( 'WooCommerce' ),
                'orderStatuses'  => OrderStatusChanged::statuses(),
                'currencySymbol' => function_exists( 'get_woocommerce_currency_symbol' )
                    ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' )
                    : '',
            ],
        ];

        wp_localize_script( ScriptName::PAGE_ADMIN, 'yayAutomationMeta', $meta );

        wp_enqueue_script( ScriptName::PAGE_ADMIN );
        if ( YayAutomation::is_prod() ) {
            wp_enqueue_style( ScriptName::STYLE_ADMIN );
        }
    }

    public function render_page() {
        echo '<div id="yay-automation" class="yay-automation-admin"></div>';
    }

    public function on_load_page() {
        remove_all_actions( 'admin_notices' );
    }
}
