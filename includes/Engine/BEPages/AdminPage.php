<?php

namespace YayAutomation\Engine\BEPages;

use YayAutomation\Engine\Register\ScriptName;
use YayAutomation\Utils\SingletonTrait;
use YayAutomation\Workflow\Definition\Catalog;
use YayAutomation\Workflow\Steps\Actions\SendInternalEmail;
use YayAutomation\YayAutomation;

defined( 'ABSPATH' ) || exit;

/** Admin Page — mounts the React app */
class AdminPage {

    use SingletonTrait;

    public const MENU_SLUG = 'yay-automation';

    public const CAPABILITY = 'manage_options';

    public $hook_suffix = null;

    protected function __construct() {
        // The submenu itself is registered by AdminShell (see YayAutomationLicenseAdapter).
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

        wp_localize_script( ScriptName::PAGE_ADMIN, 'yayAutomationMeta', self::client_meta() );

        wp_enqueue_script( ScriptName::PAGE_ADMIN );
        if ( YayAutomation::is_prod() ) {
            wp_enqueue_style( ScriptName::STYLE_ADMIN );
        }
    }

    /**
     * `window.yayAutomationMeta`. Also read by `dev/scripts/meta-fixture.sh` for the admin app's tests.
     */
    public static function client_meta(): array {
        // Shape must match apps/admin-page/src/types/localize.d.ts.
        return [
            'plugin'     => [
                'pluginUrl' => YAY_AUTOMATION_PLUGIN_URL,
                'version'   => YAY_AUTOMATION_VERSION,
            ],
            'wp'         => [
                'restRoot'       => esc_url_raw( rest_url() ),
                'restBase'       => 'yay-automation/v1',
                'restNonce'      => wp_create_nonce( 'wp_rest' ),
                'siteUrl'        => site_url(),
                'adminUrl'       => admin_url(),
                'timezoneString' => get_option( 'timezone_string' ),
                'gmtOffset'      => (float) get_option( 'gmt_offset', 0 ),
                'adminEmail'     => get_option( 'admin_email' ),
            ],
            'wc'         => [
                'active'         => class_exists( 'WooCommerce' ),
                'currencySymbol' => function_exists( 'get_woocommerce_currency_symbol' )
                    ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' )
                    : '',
            ],
            'automation' => Catalog::to_client() + [ 'email' => SendInternalEmail::sender() ],
            'docs'       => [
                /**
                 * Link under the empty "Workflow runs" section on a workflow's page.
                 *
                 * @param string $url Docs URL.
                 */
                'workflowRuns' => esc_url_raw( apply_filters( 'yay_automation_workflow_runs_docs_url', 'https://docs.yaycommerce.com/' ) ),
            ],
        ];
    }

    public function render_page() {
        echo '<div id="yay-automation" class="yay-automation-admin"></div>';
    }

    public function on_load_page() {
        remove_all_actions( 'admin_notices' );
    }
}
