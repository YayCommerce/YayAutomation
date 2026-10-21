<?php

namespace YayMatik\Engine\BEPages;

use YayMatik\Engine\API\RestAPI;
use YayMatik\Engine\Register\ScriptName;
use YayMatik\Workflow\Coupon\FreeShipping;
use YayMatik\Utils\SingletonTrait;
use YayMatik\Workflow\Definition\Catalog;
use YayMatik\Workflow\Steps\Actions\SendInternalEmail;
use YayMatik\YayMatik;

defined( 'ABSPATH' ) || exit;

/** Admin Page — mounts the React app */
class AdminPage {

    use SingletonTrait;

    public const MENU_SLUG = 'yaymatik';

    public const CAPABILITY = 'manage_options';

    public $hook_suffix = null;

    protected function __construct() {
        // The submenu itself is registered by AdminShell (see YayMatikLicenseAdapter).
        add_action( 'admin_menu', [ $this, 'admin_menu' ], 99 );
        add_filter( 'admin_body_class', [ $this, 'admin_body_class' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );
    }

    public function admin_menu() {
        $this->hook_suffix = get_plugin_page_hookname( self::MENU_SLUG, 'yaycommerce' );
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

        wp_localize_script( ScriptName::PAGE_ADMIN, 'yayMatikMeta', self::client_meta() );
        wp_set_script_translations( ScriptName::PAGE_ADMIN, 'yaymatik', YAYMATIK_PLUGIN_DIR . 'languages' );

        wp_enqueue_script( ScriptName::PAGE_ADMIN );
        if ( YayMatik::is_prod() ) {
            wp_enqueue_style( ScriptName::STYLE_ADMIN );
        }
    }

    /**
     * `window.yayMatikMeta`. Also read by `dev/scripts/meta-fixture.sh` for the admin app's tests.
     */
    public static function client_meta(): array {
        // Shape must match apps/admin-page/src/types/localize.d.ts.
        return [
            'plugin'     => [
                'pluginUrl' => YAYMATIK_PLUGIN_URL,
                'version'   => YAYMATIK_VERSION,
            ],
            'wp'         => [
                'restRoot'       => esc_url_raw( rest_url() ),
                'restBase'       => RestAPI::REST_NAMESPACE,
                'restNonce'      => wp_create_nonce( 'wp_rest' ),
                'siteUrl'        => site_url(),
                'adminUrl'       => admin_url(),
                'timezoneString' => get_option( 'timezone_string' ),
                'gmtOffset'      => (float) get_option( 'gmt_offset', 0 ),
                'adminEmail'     => get_option( 'admin_email' ),
            ],
            'wc'         => [
                'active'             => class_exists( 'WooCommerce' ),
                'currencySymbol'     => function_exists( 'get_woocommerce_currency_symbol' )
                    ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' )
                    : '',
                // What free shipping coupons need: 'coupon', 'coupon_and_minimum', or '' (they do nothing).
                'freeShippingCoupon' => FreeShipping::coupon_mode(),
            ],
            'automation' => Catalog::to_client() + [ 'email' => SendInternalEmail::sender() ],
            'docs'       => [
                /**
                 * Link under the empty "Workflow runs" section on a workflow's page.
                 *
                 * @param string $url Docs URL.
                 */
                'workflowRuns' => esc_url_raw( apply_filters( 'yaymatik_workflow_runs_docs_url', 'https://docs.yaycommerce.com/' ) ),
            ],
        ];
    }

    public function render_page() {
        echo '<div id="yaymatik" class="yaymatik-admin"></div>';
    }

    public function on_load_page() {
        remove_all_actions( 'admin_notices' );
    }
}
